// =====================================================
// Student Management Handler — djihs_enrollment_v2 schema
// File: js/student-management.js
//
// FIXES vs old version:
//  - renderStudentForm(): removed Age input (derived, not stored), removed ZipCode
//    (not in schema), replaced flat Father/Mother/Guardian inputs with data from
//    data.guardians{} (parentguardian table), fixed EnrollmentStatus dropdown
//    (removed Transferred_In — not in student.EnrollmentStatus ENUM),
//    Academic Year is now read-only (derived from enrollment.AcademicYearID FK),
//    removed IsTransferee/CreatedAt/UpdatedAt from "Additional Information" section
//  - buildFieldDiff(): removed Age, ZipCode, flat parent fields;
//    guardian diff now tracks Father/Mother/Guardian via guardians{} structure
//  - saveStudent() formData: same removals, guardian data sent as nested guardians{}
//  - normalizeStatus(): removed Transferred_In (not in enrollment.Status ENUM)
//  - getStatusBadge(): maps all 6 enrollment.Status values correctly
//  - showStudentDetailsModal(): passes student.guardians{} to renderStudentForm
// =====================================================

class StudentManagementHandler {
    constructor() {
        this.currentUser          = null;
        this.students             = [];
        this.filteredStudents     = [];
        this.currentPage          = 1;
        this.itemsPerPage         = 10;
        this.selectedStudents     = new Set();
        this.strands              = [];
        this.gradeLevels          = [];
        this.currentEditingStudent = null;
        this.init();
    }

    init() {
        this.currentUser = this.getCurrentUser();
        if (!this.currentUser) {
            alert('You must be logged in to access this page.');
            window.location.href = '../login.html';
            return;
        }
        this.bindEventListeners();
        this.loadGradeLevels();
        this.loadStrands();
        this.loadStudents();
        this.displayUserInfo();
        this.documentHandler = new DocumentSubmissionHandler();
    }

    displayUserInfo() {
        const u  = this.currentUser;
        const el = (id) => document.getElementById(id);
        if (u.FirstName && u.LastName) {
            if (el('userName'))    el('userName').textContent    = `${u.FirstName} ${u.LastName}`;
            if (el('userInitials'))el('userInitials').textContent = (u.FirstName[0] + u.LastName[0]).toUpperCase();
            if (el('userRole'))    el('userRole').textContent    = u.Role?.replace(/_/g, ' ') || '--';
        }
    }

    getCurrentUser() {
        try { return JSON.parse(localStorage.getItem('user') || 'null'); }
        catch { return null; }
    }

    // ──────────────────────────────────────────────
    // EVENT LISTENERS
    // ──────────────────────────────────────────────
    bindEventListeners() {
        // Search — debounced
        const searchInput = document.getElementById('search');
        if (searchInput) {
            let t;
            searchInput.addEventListener('input', () => {
                clearTimeout(t);
                t = setTimeout(() => this.applyFilters(), 300);
            });
        }

        ['grade-level','section','enrollment-status','academic-year','strand'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', () => this.applyFilters());
        });

        document.querySelector('button[data-action="apply-filters"]')
            ?.addEventListener('click',  () => this.applyFilters());
        document.querySelector('button[data-action="clear-filters"]')
            ?.addEventListener('click',  () => this.clearFilters());
        document.querySelector('button[data-action="add-student"]')
            ?.addEventListener('click',  () => this.addNewStudent());

        document.querySelector('thead input[type="checkbox"]')
            ?.addEventListener('change', (e) => this.handleSelectAll(e.target.checked));

        document.getElementById('grade-level')
            ?.addEventListener('change', () => this.toggleStrandFilter());
    }

    toggleStrandFilter() {
        const grade  = document.getElementById('grade-level');
        const strand = document.getElementById('strand');
        const wrap   = strand?.closest('.flex.flex-col');
        if (!grade || !wrap) return;
        const show = (grade.value === '11' || grade.value === '12');
        wrap.classList.toggle('hidden', !show);
        if (!show && strand) strand.selectedIndex = 0;
    }

    // ──────────────────────────────────────────────
    // DATA LOADING
    // ──────────────────────────────────────────────
    async loadGradeLevels() {
        try {
            const r = await fetch('../backend/api/student-update.php?action=get_grade_levels');
            const d = await r.json();
            if (d.success) this.gradeLevels = d.gradeLevels || [];
        } catch (e) { console.error('Error loading grade levels:', e); }
    }

    async loadStrands() {
        try {
            const r = await fetch('../backend/api/students.php?action=get_strands');
            const d = await r.json();
            if (d.success) {
                this.strands = d.strands || [];
                this.populateStrandFilter();
            }
        } catch (e) { console.error('Error loading strands:', e); }
    }

    populateStrandFilter() {
        const sel = document.getElementById('strand');
        if (!sel) return;
        sel.innerHTML = '<option value="">All Strands</option>';
        this.strands.forEach(s => {
            sel.innerHTML += `<option value="${this.esc(s.StrandName)}">${this.esc(s.StrandCode)} – ${this.esc(s.StrandName)}</option>`;
        });
    }

    async loadStudents() {
        try {
            this.showLoading(true);
            const r = await fetch('../backend/api/students.php?action=list');
            const d = await r.json();
            if (!d.success) throw new Error(d.message || 'Failed to load students');
            this.students         = d.data || [];
            this.filteredStudents = [...this.students];
            this.populateFilterOptions();
            this.renderTable();
            this.updatePagination();
        } catch (e) {
            console.error('Error loading students:', e);
            alert('Error loading students. Please refresh the page.');
        } finally {
            this.showLoading(false);
        }
    }

    // ──────────────────────────────────────────────
    // FILTER OPTIONS
    // ──────────────────────────────────────────────
    populateFilterOptions() {
        const grades  = new Set();
        const sections= new Set();
        const statuses= new Set();
        const years   = new Set();

        this.students.forEach(s => {
            const gm = s.GradeLevel?.match(/\d+/);
            if (gm) grades.add(gm[0]);
            if (s.Section)     sections.add(s.Section);
            // enrollment.Status (Pending|Confirmed|Cancelled|For_Review|Dropped|Transferred_Out)
            if (s.EnrollmentConfirmStatus) statuses.add(s.EnrollmentConfirmStatus);
            // student.EnrollmentStatus (Active|Cancelled|Transferred_Out|Graduated|Dropped)
            if (s.EnrollmentStatus)        statuses.add(s.EnrollmentStatus);
            if (s.AcademicYear) years.add(s.AcademicYear);
        });

        this.populate('grade-level', Array.from(grades).sort((a,b)=>+a-+b)
            .map(g => `<option value="${g}">Grade ${g}</option>`));

        this.populate('section', Array.from(sections).sort()
            .map(s => `<option value="${this.esc(s)}">${this.esc(s)}</option>`));

        // Status options — all valid enrollment.Status ENUM values
        const statusLabels = {
            Confirmed:       'Enrolled (Confirmed)',
            Pending:         'Pending',
            For_Review:      'For Review',
            Cancelled:       'Cancelled',
            Dropped:         'Dropped',
            Transferred_Out: 'Transferred Out',
            Active:          'Active',
            Graduated:       'Graduated',
        };
        this.populate('enrollment-status', Array.from(statuses).sort()
            .map(s => `<option value="${this.esc(s)}">${this.esc(statusLabels[s] || s)}</option>`));

        this.populate('academic-year', Array.from(years).sort().reverse()
            .map(y => `<option value="${this.esc(y)}">${this.esc(y)}</option>`));
    }

    populate(id, options) {
        const sel = document.getElementById(id);
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = sel.options[0].outerHTML + options.join('');
        if (cur) sel.value = cur;
    }

    // ──────────────────────────────────────────────
    // FILTERS & STATUS MAPPING
    //
    // FIX: removed Transferred_In from normalizeStatus
    //      (not in enrollment.Status ENUM)
    // ──────────────────────────────────────────────
    normalizeStatus(status) {
        const map = {
            Confirmed:       'Enrolled',
            Active:          'Enrolled',
            Pending:         'Pending',
            For_Review:      'For Review',
            Cancelled:       'Cancelled',
            Dropped:         'Dropped',
            Transferred_Out: 'Transferred Out',
            Graduated:       'Graduated',
        };
        return map[status] || status;
    }

    applyFilters() {
        const search  = document.getElementById('search')?.value.toLowerCase().trim() || '';
        const grade   = document.getElementById('grade-level')?.value   || '';
        const section = document.getElementById('section')?.value        || '';
        const status  = document.getElementById('enrollment-status')?.value || '';
        const year    = document.getElementById('academic-year')?.value  || '';
        const strand  = document.getElementById('strand')?.value         || '';

        this.filteredStudents = this.students.filter(s => {
            if (search  && !s.FullName?.toLowerCase().includes(search)
                        && !s.LRN?.toLowerCase().includes(search)
                        && !String(s.StudentID).includes(search)) return false;
            if (grade   && s.GradeLevel?.match(/\d+/)?.[0] !== grade) return false;
            if (section && s.Section !== section)                        return false;
            if (year    && s.AcademicYear !== year)                      return false;
            if (strand  && s.StrandName !== strand)                      return false;
            if (status) {
                const rawStatus = s.EnrollmentConfirmStatus || s.EnrollmentStatus;
                if (rawStatus !== status && this.normalizeStatus(rawStatus) !== status) return false;
            }
            return true;
        });

        this.currentPage = 1;
        this.renderTable();
        this.updatePagination();
    }

    clearFilters() {
        ['search','grade-level','section','enrollment-status','academic-year','strand']
            .forEach(id => {
                const el = document.getElementById(id);
                if (el) el.tagName === 'INPUT' ? el.value = '' : el.selectedIndex = 0;
            });
        document.getElementById('strand')?.closest('.flex.flex-col')?.classList.add('hidden');
        this.filteredStudents = [...this.students];
        this.currentPage = 1;
        this.renderTable();
        this.updatePagination();
    }

    // ──────────────────────────────────────────────
    // TABLE RENDER
    // ──────────────────────────────────────────────
    renderTable() {
        const tbody = document.querySelector('tbody');
        if (!tbody) return;

        const start = (this.currentPage - 1) * this.itemsPerPage;
        const page  = this.filteredStudents.slice(start, start + this.itemsPerPage);

        if (!page.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        <div class="flex flex-col items-center gap-3">
                            <span class="material-symbols-outlined text-6xl text-gray-300">inbox</span>
                            <p class="text-lg font-medium">No students found</p>
                            <p class="text-sm">Try adjusting your search or filters</p>
                        </div>
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = page.map(s => `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                    <input class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary
                                  focus:ring-primary dark:border-gray-600 dark:bg-gray-700"
                           type="checkbox" data-student-id="${s.StudentID}"
                           ${this.selectedStudents.has(s.StudentID) ? 'checked' : ''}/>
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 font-mono">
                    ${this.esc(s.LRN || 'N/A')}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white">
                    ${this.esc(this.formatName(s.FullName))}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${this.esc(this.extractGradeNumber(s.GradeLevel))}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${this.esc(s.StrandName || '—')}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${this.esc(s.Section || '—')}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                    ${this.esc(s.AcademicYear || '—')}
                </td>
                <td class="whitespace-nowrap px-3 py-4 text-sm">
                    ${this.getStatusBadge(s.EnrollmentConfirmStatus || s.EnrollmentStatus)}
                </td>
                <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                    <div class="flex items-center justify-end gap-2">
                        <button class="btn-view text-gray-400 hover:text-primary transition-colors"
                                data-student-id="${s.StudentID}" title="View Details">
                            <span class="material-symbols-outlined" style="font-size:20px;">visibility</span>
                        </button>
                        <button class="btn-edit text-gray-400 hover:text-primary transition-colors"
                                data-student-id="${s.StudentID}" title="Edit">
                            <span class="material-symbols-outlined" style="font-size:20px;">edit</span>
                        </button>
                        <button class="btn-more text-gray-400 hover:text-red-500 transition-colors"
                                data-student-id="${s.StudentID}" title="More Options">
                            <span class="material-symbols-outlined" style="font-size:20px;">more_vert</span>
                        </button>
                    </div>
                </td>
            </tr>`).join('');

        // Checkboxes
        tbody.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', e => {
                const id = parseInt(e.target.dataset.studentId);
                e.target.checked ? this.selectedStudents.add(id) : this.selectedStudents.delete(id);
                this.updateSelectAllCheckbox();
            });
        });

        tbody.querySelectorAll('.btn-view')
            .forEach(b => b.addEventListener('click', e =>
                this.viewStudent(parseInt(e.currentTarget.dataset.studentId))));
        tbody.querySelectorAll('.btn-edit')
            .forEach(b => b.addEventListener('click', e =>
                this.editStudent(parseInt(e.currentTarget.dataset.studentId))));
        tbody.querySelectorAll('.btn-more')
            .forEach(b => b.addEventListener('click', e =>
                this.showMoreOptions(parseInt(e.currentTarget.dataset.studentId))));

        this.updateSelectAllCheckbox();
    }

    updateSelectAllCheckbox() {
        const cb   = document.querySelector('thead input[type="checkbox"]');
        if (!cb) return;
        const start = (this.currentPage - 1) * this.itemsPerPage;
        const page  = this.filteredStudents.slice(start, start + this.itemsPerPage);
        const all   = page.length > 0 && page.every(s => this.selectedStudents.has(s.StudentID));
        const some  = page.some(s => this.selectedStudents.has(s.StudentID));
        cb.checked       = all;
        cb.indeterminate = some && !all;
    }

    handleSelectAll(checked) {
        const start = (this.currentPage - 1) * this.itemsPerPage;
        this.filteredStudents.slice(start, start + this.itemsPerPage).forEach(s =>
            checked ? this.selectedStudents.add(s.StudentID) : this.selectedStudents.delete(s.StudentID));
        this.renderTable();
    }

    // ──────────────────────────────────────────────
    // STATUS BADGE
    //
    // Maps both enrollment.Status ENUM values AND student.EnrollmentStatus ENUM values.
    // enrollment.Status:  Pending|Confirmed|Cancelled|For_Review|Dropped|Transferred_Out
    // student.EnrollmentStatus: Active|Cancelled|Transferred_Out|Graduated|Dropped
    // ──────────────────────────────────────────────
    getStatusBadge(status) {
        const map = {
            Confirmed:       { cls: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',     label: 'Enrolled'        },
            Active:          { cls: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',     label: 'Active'          },
            Pending:         { cls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300', label: 'Pending'         },
            For_Review:      { cls: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',         label: 'For Review'      },
            Cancelled:       { cls: 'bg-gray-100 text-gray-700 dark:bg-gray-900/40 dark:text-gray-300',         label: 'Cancelled'       },
            Dropped:         { cls: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',             label: 'Dropout'         },
            Transferred_Out: { cls: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300', label: 'Transferred Out' },
            Graduated:       { cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300', label: 'Graduated'   },
        };
        const cfg = map[status] || map['Pending'];
        return `<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${cfg.cls}">${cfg.label}</span>`;
    }

    // ──────────────────────────────────────────────
    // VIEW / EDIT STUDENT
    // ──────────────────────────────────────────────
    async viewStudent(studentId) {
        try {
            const r = await fetch(`../backend/api/students.php?action=details&id=${studentId}`);
            const d = await r.json();
            if (d.success) this.showStudentDetailsModal(d.data, false);
            else           alert('Failed to load student details: ' + d.message);
        } catch (e) {
            console.error(e);
            alert('Error loading student details');
        }
    }

    async editStudent(studentId) {
        if (this.currentUser.Role !== 'Adviser') {
            alert('Only Advisers can submit student information edits. Registrars and ICT Coordinators approve these changes.');
            return;
        }
        try {
            const r = await fetch(`../backend/api/students.php?action=details&id=${studentId}`);
            const d = await r.json();
            if (d.success) this.showStudentDetailsModal(d.data, true);
            else           alert('Failed to load student details: ' + d.message);
        } catch (e) {
            console.error(e);
            alert('Error loading student details');
        }
    }

    // ──────────────────────────────────────────────
    // STUDENT DETAILS MODAL
    // ──────────────────────────────────────────────
    showStudentDetailsModal(student, editMode = false) {
        this.currentEditingStudent = student;
        document.getElementById('studentDetailsModal')?.remove();

        const isAdviser  = this.currentUser.Role === 'Adviser';
        const modal      = document.createElement('div');
        modal.id         = 'studentDetailsModal';
        modal.className  = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';

        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-6xl w-full max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-between z-10">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            ${editMode ? 'Edit Student Information' : 'Student Details'}
                        </h3>
                        ${editMode ? `<p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
                            ⚠ Changes will be submitted for Registrar/ICT approval before taking effect
                        </p>` : ''}
                    </div>
                    <button onclick="document.getElementById('studentDetailsModal').remove()"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                        <span class="material-icons-outlined">close</span>
                    </button>
                </div>

                <div class="p-6">
                    ${this.renderStudentForm(student, editMode)}
                </div>

                <div class="sticky bottom-0 bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    ${editMode ? `
                        <button id="btn-cancel-edit" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 transition-colors">Cancel</button>
                        <button id="btn-save-student" class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                            <span class="material-icons-outlined text-[18px]">send</span>Submit for Approval
                        </button>
                    ` : `
                        ${isAdviser ? `
                        <button id="btn-edit-student" data-student-id="${student.StudentID}"
                                class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                            <span class="material-icons-outlined text-[18px]">edit</span>Edit Student
                        </button>` : ''}
                        <button onclick="document.getElementById('studentDetailsModal').remove()"
                                class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 transition-colors">Close</button>
                    `}
                </div>
            </div>`;

        document.body.appendChild(modal);

        // Grade/Strand select handlers (edit mode)
        if (editMode) {
            document.getElementById('btn-cancel-edit')?.addEventListener('click', () => this.cancelEdit());
            document.getElementById('btn-save-student')?.addEventListener('click', () => this.saveStudent());
            document.getElementById('edit-grade')?.addEventListener('change',  () => this.handleGradeChange());
            document.getElementById('edit-strand')?.addEventListener('change', () => this.handleStrandChange());
        } else {
            document.getElementById('btn-edit-student')?.addEventListener('click', e => {
                document.getElementById('studentDetailsModal').remove();
                this.editStudent(parseInt(e.currentTarget.dataset.studentId));
            });
        }

        // Load sections for current grade/strand
        if (student.GradeLevelID) {
            this.loadSectionsForGrade(student.GradeLevelID, student.StrandID, student.SectionID);
        }
    }

    // ──────────────────────────────────────────────
    // RENDER STUDENT FORM
    //
    // KEY FIXES:
    //  - Removed Age input — derived via TIMESTAMPDIFF; shown read-only from data.Age
    //  - Removed ZipCode — not in student table schema
    //  - Father/Mother/Guardian: read from data.guardians{} (parentguardian table)
    //    NOT from flat data.FatherLastName etc. (those columns don't exist)
    //  - EnrollmentStatus dropdown: removed Transferred_In
    //    (student.EnrollmentStatus ENUM: Active|Cancelled|Transferred_Out|Graduated|Dropped)
    //  - Academic Year: read-only display (derived from enrollment.AcademicYearID FK)
    //  - Additional Info (view only): removed IsTransferee, CreatedAt, UpdatedAt
    // ──────────────────────────────────────────────
    renderStudentForm(student, editMode) {
        const dis = editMode ? '' : 'disabled';
        const ic  = editMode
            ? 'form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white'
            : 'form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-100 dark:text-gray-500 cursor-not-allowed';

        const isSHS = student.GradeLevelID >= 5; // GradeLevelID 5 = Grade 11, 6 = Grade 12

        // Extract guardians from data.guardians{} keyed map
        const father   = student.guardians?.Father   || {};
        const mother   = student.guardians?.Mother   || {};
        const guardian = student.guardians?.Guardian || {};

        const guardianBlock = (label, prefix, data) => `
            <div>
                <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-2">${label}</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                        <input type="text" id="edit-${prefix}-last" value="${this.esc(data.LastName||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                        <input type="text" id="edit-${prefix}-first" value="${this.esc(data.FirstName||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                        <input type="text" id="edit-${prefix}-middle" value="${this.esc(data.MiddleName||'')}" ${dis} class="${ic}"/>
                    </div>
                </div>
            </div>`;

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
                        <input type="text" id="edit-lrn" value="${this.esc(student.LRN||'')}" ${dis} class="${ic}" maxlength="12"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                        <input type="text" id="edit-lastname" value="${this.esc(student.LastName||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                        <input type="text" id="edit-firstname" value="${this.esc(student.FirstName||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                        <input type="text" id="edit-middlename" value="${this.esc(student.MiddleName||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Extension Name</label>
                        <input type="text" id="edit-extension" value="${this.esc(student.ExtensionName||'')}" ${dis}
                               placeholder="Jr., Sr., III, etc." class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
                        <input type="date" id="edit-dob" value="${this.esc(student.DateOfBirth||'')}" ${dis} class="${ic}"/>
                    </div>
                    <!-- Age: derived from DateOfBirth via TIMESTAMPDIFF — always read-only -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age</label>
                        <input type="text" id="edit-age" value="${student.Age != null ? student.Age + ' years old' : '—'}"
                               disabled class="form-input w-full rounded-lg border-gray-300 dark:border-gray-600
                                              dark:bg-gray-100 dark:text-gray-500 cursor-not-allowed"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
                        <select id="edit-gender" ${dis} class="${ic}">
                            <option value="Male"   ${student.Gender==='Male'   ? 'selected' : ''}>Male</option>
                            <option value="Female" ${student.Gender==='Female' ? 'selected' : ''}>Female</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Religion</label>
                        <input type="text" id="edit-religion" value="${this.esc(student.Religion||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mother Tongue</label>
                        <input type="text" id="edit-mother-tongue" value="${this.esc(student.MotherTongue||'Tagalog')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact Number</label>
                        <input type="tel" id="edit-contact" value="${this.esc(student.ContactNumber||'')}" ${dis} class="${ic}"/>
                    </div>
                </div>
                <!-- Flags -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="edit-ip" ${student.IsIPCommunity ? 'checked' : ''} ${dis}
                               class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"/>
                        <label for="edit-ip" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Member of Indigenous Peoples Community
                        </label>
                    </div>
                    <div>
                        <input type="text" id="edit-ip-specify" value="${this.esc(student.IPCommunitySpecify||'')}" ${dis}
                               placeholder="Specify IP Community" class="${ic}"/>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="edit-pwd" ${student.IsPWD ? 'checked' : ''} ${dis}
                               class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"/>
                        <label for="edit-pwd" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Person with Disability (PWD)
                        </label>
                    </div>
                    <div>
                        <input type="text" id="edit-pwd-specify" value="${this.esc(student.PWDSpecify||'')}" ${dis}
                               placeholder="Specify Disability" class="${ic}"/>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="edit-4ps" ${student.Is4PsBeneficiary ? 'checked' : ''} ${dis}
                               class="form-checkbox h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"/>
                        <label for="edit-4ps" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            4Ps Beneficiary
                        </label>
                    </div>
                </div>
            </div>

            <!-- Address Information -->
            <div>
                <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">home</span>
                    Address Information
                </h4>
                <!-- Note: ZipCode is NOT in the student table schema — removed -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">House Number</label>
                        <input type="text" id="edit-house" value="${this.esc(student.HouseNumber||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sitio / Street</label>
                        <input type="text" id="edit-street" value="${this.esc(student.SitioStreet||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Barangay</label>
                        <input type="text" id="edit-barangay" value="${this.esc(student.Barangay||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Municipality</label>
                        <input type="text" id="edit-municipality" value="${this.esc(student.Municipality||'')}" ${dis} class="${ic}"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Province</label>
                        <input type="text" id="edit-province" value="${this.esc(student.Province||'')}" ${dis} class="${ic}"/>
                    </div>
                </div>
            </div>

            <!-- Parent / Guardian Information -->
            <!-- Data from parentguardian table (RelationshipType: Father|Mother|Guardian) -->
            <div>
                <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">family_restroom</span>
                    Parent / Guardian Information
                </h4>
                <div class="grid grid-cols-1 gap-6">
                    ${guardianBlock("Father's Information",   'father',   father)}
                    ${guardianBlock("Mother's Information",   'mother',   mother)}
                    ${guardianBlock("Guardian's Information (if applicable)", 'guardian', guardian)}
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
                        <select id="edit-grade" ${dis} class="${ic}">
                            <option value="">Select Grade Level</option>
                            ${this.gradeLevels.map(gl => `
                                <option value="${gl.GradeLevelID}" ${student.GradeLevelID == gl.GradeLevelID ? 'selected' : ''}>
                                    ${this.esc(gl.GradeLevelName)}
                                </option>`).join('')}
                        </select>
                    </div>
                    <!-- Academic Year: read-only — derived from enrollment.AcademicYearID FK, not a stored string -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Academic Year</label>
                        <input type="text" id="edit-academic-year" value="${this.esc(student.AcademicYear||'')}"
                               disabled class="form-input w-full rounded-lg border-gray-300 dark:border-gray-600
                                              dark:bg-gray-100 dark:text-gray-500 cursor-not-allowed"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Enrollment Type</label>
                        <input type="text" id="edit-enrollment-type" value="${this.esc((student.EnrollmentType||'').replace(/_/g,' '))}"
                               disabled class="form-input w-full rounded-lg border-gray-300 dark:border-gray-600
                                              dark:bg-gray-100 dark:text-gray-500 cursor-not-allowed"/>
                    </div>
                    <div>
                        <!-- student.EnrollmentStatus ENUM: Active|Cancelled|Transferred_Out|Graduated|Dropped
                             NOTE: Transferred_In is NOT in this ENUM — removed -->
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Enrollment Status</label>
                        <select id="edit-status" ${dis} class="${ic}">
                            <option value="Active"          ${student.EnrollmentStatus==='Active'          ? 'selected' : ''}>Active</option>
                            <option value="Cancelled"       ${student.EnrollmentStatus==='Cancelled'       ? 'selected' : ''}>Cancelled</option>
                            <option value="Dropped"         ${student.EnrollmentStatus==='Dropped'         ? 'selected' : ''}>Dropped</option>
                            <option value="Transferred_Out" ${student.EnrollmentStatus==='Transferred_Out' ? 'selected' : ''}>Transferred Out</option>
                            <option value="Graduated"       ${student.EnrollmentStatus==='Graduated'       ? 'selected' : ''}>Graduated</option>
                        </select>
                    </div>
                </div>

                <!-- Strand (SHS only — GradeLevelID 5 or 6) -->
                <div id="strand-container" class="mt-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700
                                                   bg-gray-50 dark:bg-gray-900/50 ${isSHS ? '' : 'hidden'}">
                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-3">Senior High School Strand</p>
                    <div class="max-w-sm">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Strand</label>
                        <select id="edit-strand" ${dis} class="${ic}">
                            <option value="">Select Strand</option>
                            ${this.strands.map(s => `
                                <option value="${s.StrandID}" ${student.StrandID == s.StrandID ? 'selected' : ''}>
                                    ${this.esc(s.StrandCode)} – ${this.esc(s.StrandName)}
                                </option>`).join('')}
                        </select>
                    </div>
                </div>

                <!-- Section -->
                <div class="mt-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-3">Class Section</p>
                    <div class="max-w-sm">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Section</label>
                        <select id="edit-section" ${dis} class="${ic}">
                            <option value="">Select Section</option>
                        </select>
                        ${editMode ? `<p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            Sections shown match the selected grade level${isSHS ? ' and strand' : ''}.
                        </p>` : ''}
                    </div>
                </div>
            </div>

        </div>`;
    }

    handleGradeChange() {
        const grade  = document.getElementById('edit-grade');
        const wrap   = document.getElementById('strand-container');
        const strand = document.getElementById('edit-strand');
        if (!grade) return;
        const id = parseInt(grade.value);
        const isSHS = id >= 5;
        wrap?.classList.toggle('hidden', !isSHS);
        if (!isSHS && strand) strand.value = '';
        this.loadSectionsForGrade(id, isSHS ? strand?.value : null);
    }

    handleStrandChange() {
        const grade  = document.getElementById('edit-grade');
        const strand = document.getElementById('edit-strand');
        if (!grade) return;
        this.loadSectionsForGrade(parseInt(grade.value), strand?.value || null);
    }

    async loadSectionsForGrade(gradeLevelId, strandId = null, selectedSectionId = null) {
        const sel = document.getElementById('edit-section');
        if (!sel || !gradeLevelId) return;
        const overrideSection = selectedSectionId ?? this.currentEditingStudent?.SectionID;
        try {
            let url = `../backend/api/student-update.php?action=get_sections&grade_level=${gradeLevelId}`;
            if (strandId) url += `&strand_id=${strandId}`;
            const r = await fetch(url);
            const d = await r.json();
            if (d.success) {
                sel.innerHTML = '<option value="">Select Section</option>';
                d.sections.forEach(s => {
                    const enrolled = s.CurrentEnrollment ?? 0;
                    sel.innerHTML += `
                        <option value="${s.SectionID}" ${overrideSection == s.SectionID ? 'selected' : ''}>
                            ${this.esc(s.SectionName)} (${enrolled}/${s.Capacity})
                        </option>`;
                });
            }
        } catch (e) { console.error('Error loading sections:', e); }
    }

    // ──────────────────────────────────────────────
    // BUILD FIELD DIFF (for revision request audit)
    //
    // FIXES:
    //  - Removed Age (derived, not stored)
    //  - Removed ZipCode (not in student table)
    //  - Removed flat Father/Mother/Guardian fields (not in student table)
    //  - Added guardian diff tracking via guardians{} structure
    // ──────────────────────────────────────────────
    buildFieldDiff(formData) {
        const orig = this.currentEditingStudent;

        // Core student fields (schema columns only)
        const studentFields = {
            LRN:               { old: orig.LRN,                new: formData.LRN               },
            LastName:          { old: orig.LastName,           new: formData.LastName           },
            FirstName:         { old: orig.FirstName,          new: formData.FirstName          },
            MiddleName:        { old: orig.MiddleName,         new: formData.MiddleName         },
            ExtensionName:     { old: orig.ExtensionName,      new: formData.ExtensionName      },
            DateOfBirth:       { old: orig.DateOfBirth,        new: formData.DateOfBirth        },
            Gender:            { old: orig.Gender,             new: formData.Gender             },
            Religion:          { old: orig.Religion,           new: formData.Religion           },
            MotherTongue:      { old: orig.MotherTongue,       new: formData.MotherTongue       },
            ContactNumber:     { old: orig.ContactNumber,      new: formData.ContactNumber      },
            IsIPCommunity:     { old: String(orig.IsIPCommunity    ||0), new: String(formData.IsIPCommunity    ) },
            IPCommunitySpecify:{ old: orig.IPCommunitySpecify, new: formData.IPCommunitySpecify },
            IsPWD:             { old: String(orig.IsPWD            ||0), new: String(formData.IsPWD            ) },
            PWDSpecify:        { old: orig.PWDSpecify,         new: formData.PWDSpecify         },
            Is4PsBeneficiary:  { old: String(orig.Is4PsBeneficiary||0), new: String(formData.Is4PsBeneficiary ) },
            HouseNumber:       { old: orig.HouseNumber,        new: formData.HouseNumber        },
            SitioStreet:       { old: orig.SitioStreet,        new: formData.SitioStreet        },
            Barangay:          { old: orig.Barangay,           new: formData.Barangay           },
            Municipality:      { old: orig.Municipality,       new: formData.Municipality       },
            Province:          { old: orig.Province,           new: formData.Province           },
            GradeLevelID:      { old: String(orig.GradeLevelID||''), new: String(formData.GradeLevelID||'') },
            StrandID:          { old: String(orig.StrandID    ||''), new: String(formData.StrandID    ||'') },
            SectionID:         { old: String(orig.SectionID   ||''), new: String(formData.SectionID   ||'') },
            EnrollmentStatus:  { old: orig.EnrollmentStatus,   new: formData.EnrollmentStatus   },
        };

        const changes = [];
        for (const [field, {old: o, new: n}] of Object.entries(studentFields)) {
            const ov = (o ?? '').toString().trim();
            const nv = (n ?? '').toString().trim();
            if (ov !== nv) changes.push({ field, oldValue: ov, newValue: nv });
        }

        // Guardian diffs — compare via guardians{} structure
        const origGuardians = orig.guardians || {};
        const newGuardians  = formData.guardians || {};

        for (const type of ['Father', 'Mother', 'Guardian']) {
            const og = origGuardians[type] || {};
            const ng = newGuardians[type]  || {};
            for (const col of ['LastName', 'FirstName', 'MiddleName']) {
                const ov = (og[col] ?? '').toString().trim();
                const nv = (ng[col] ?? '').toString().trim();
                if (ov !== nv) changes.push({ field: `${type}_${col}`, oldValue: ov, newValue: nv });
            }
            if (type === 'Guardian') {
                const ov = (og.GuardianRelationship ?? '').trim();
                const nv = (ng.GuardianRelationship ?? '').trim();
                if (ov !== nv) changes.push({ field: 'Guardian_Relationship', oldValue: ov, newValue: nv });
            }
        }

        return changes;
    }

    // ──────────────────────────────────────────────
    // SAVE STUDENT (submit revision request)
    //
    // FIXES:
    //  - Removed Age, ZipCode from formData
    //  - Guardian data sent as nested guardians{} object (not flat keys)
    //  - AcademicYear is read-only — not included in editable formData
    // ──────────────────────────────────────────────
    async saveStudent() {
        try {
            const v  = (id) => document.getElementById(id)?.value?.trim() ?? '';
            const cb = (id) => document.getElementById(id)?.checked ? 1 : 0;

            const formData = {
                StudentID:          this.currentEditingStudent.StudentID,
                LRN:                v('edit-lrn'),
                LastName:           v('edit-lastname'),
                FirstName:          v('edit-firstname'),
                MiddleName:         v('edit-middlename'),
                ExtensionName:      v('edit-extension'),
                DateOfBirth:        v('edit-dob'),
                Gender:             v('edit-gender'),
                Religion:           v('edit-religion'),
                MotherTongue:       v('edit-mother-tongue'),
                ContactNumber:      v('edit-contact'),
                IsIPCommunity:      cb('edit-ip'),
                IPCommunitySpecify: v('edit-ip-specify'),
                IsPWD:              cb('edit-pwd'),
                PWDSpecify:         v('edit-pwd-specify'),
                Is4PsBeneficiary:   cb('edit-4ps'),
                HouseNumber:        v('edit-house'),
                SitioStreet:        v('edit-street'),
                Barangay:           v('edit-barangay'),
                Municipality:       v('edit-municipality'),
                Province:           v('edit-province'),
                GradeLevelID:       v('edit-grade'),
                StrandID:           v('edit-strand') || null,
                SectionID:          v('edit-section') || null,
                EnrollmentStatus:   v('edit-status'),
                // Guardian data as nested object — maps to parentguardian table
                guardians: {
                    Father:   { LastName: v('edit-father-last'),   FirstName: v('edit-father-first'),   MiddleName: v('edit-father-middle')   },
                    Mother:   { LastName: v('edit-mother-last'),   FirstName: v('edit-mother-first'),   MiddleName: v('edit-mother-middle')   },
                    Guardian: { LastName: v('edit-guardian-last'), FirstName: v('edit-guardian-first'), MiddleName: v('edit-guardian-middle') },
                },
                RequestedBy:  this.currentUser.UserID,
                RequesterRole: this.currentUser.Role,
            };

            if (!formData.LRN || !formData.LastName || !formData.FirstName) {
                alert('Please fill in all required fields (LRN, Last Name, First Name).');
                return;
            }

            const changedFields = this.buildFieldDiff(formData);
            if (!changedFields.length) {
                alert('No changes detected.');
                return;
            }

            const payload = {
                ...formData,
                ChangedFields: changedFields,
                StudentName:   `${formData.LastName}, ${formData.FirstName}`,
            };

            const r = await fetch('../backend/api/revision-requests.php?action=create_bulk_update', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            const result = await r.json();

            if (result.success) {
                alert(
                    `Changes submitted for approval!\n\n` +
                    `Request ID: ${result.requestId}\n` +
                    `${changedFields.length} field(s) flagged for review.\n\n` +
                    `A Registrar or ICT Coordinator will review your updates.`
                );
                document.getElementById('studentDetailsModal').remove();
                this.loadStudents();
            } else {
                alert('Failed to submit changes: ' + (result.message || 'Unknown error'));
            }
        } catch (e) {
            console.error('Error saving student:', e);
            alert('Error saving student information');
        }
    }

    cancelEdit() {
        if (confirm('Cancel? Any unsaved changes will be lost.'))
            document.getElementById('studentDetailsModal').remove();
    }

    // ──────────────────────────────────────────────
    // MORE OPTIONS MENU
    // ──────────────────────────────────────────────
    showMoreOptions(studentId) {
        const student = this.students.find(s => s.StudentID === studentId);
        const status  = student?.EnrollmentConfirmStatus || student?.EnrollmentStatus;
        const role    = this.currentUser.Role;
        const isApprover = ['Registrar','ICT_Coordinator'].includes(role);
        const isAdviser  = role === 'Adviser';
        const canDocs    = isAdviser || isApprover;

        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">Student Actions</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Role: ${role?.replace(/_/g,' ')}</p>
                </div>
                <div class="p-6 space-y-2">
                    <button class="btn-modal-view w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">visibility</span>
                        <span class="text-gray-900 dark:text-white">View Full Details</span>
                    </button>
                    ${isAdviser ? `
                    <button class="btn-modal-edit w-full text-left px-4 py-3 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg flex items-center gap-3 text-blue-600 dark:text-blue-400">
                        <span class="material-symbols-outlined">edit</span>
                        <div><span class="block">Edit Information</span><span class="text-xs opacity-75">Submit changes for approval</span></div>
                    </button>` : ''}
                    ${isApprover ? `
                    <button class="btn-modal-review-revisions w-full text-left px-4 py-3 hover:bg-orange-50 dark:hover:bg-orange-900/20 rounded-lg flex items-center gap-3 text-orange-600 dark:text-orange-400">
                        <span class="material-symbols-outlined">fact_check</span>
                        <div><span class="block">Review Pending Revisions</span><span class="text-xs opacity-75">Approve or reject changes</span></div>
                    </button>` : ''}
                    ${canDocs ? `
                    <button class="btn-modal-documents w-full text-left px-4 py-3 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg flex items-center gap-3 text-indigo-600 dark:text-indigo-400">
                        <span class="material-symbols-outlined">description</span>
                        <div><span class="block">Document Checklist</span><span class="text-xs opacity-75">Manage student documents</span></div>
                    </button>` : ''}
                    <button class="btn-modal-remarks w-full text-left px-4 py-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg flex items-center gap-3">
                        <span class="material-symbols-outlined text-gray-600 dark:text-gray-400">comment</span>
                        <span class="text-gray-900 dark:text-white">Add Remarks</span>
                    </button>
                    ${isApprover ? `
                    <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
                    ${status !== 'Cancelled' ? `
                    <button class="btn-modal-cancel w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-900/20 rounded-lg flex items-center gap-3 text-gray-600 dark:text-gray-400">
                        <span class="material-symbols-outlined">cancel</span><span>Cancel Enrollment</span>
                    </button>` : ''}
                    ${status !== 'Dropped' ? `
                    <button class="btn-modal-dropout w-full text-left px-4 py-3 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg flex items-center gap-3 text-red-600 dark:text-red-400">
                        <span class="material-symbols-outlined">person_off</span><span>Mark as Dropout</span>
                    </button>` : ''}
                    ${status !== 'Transferred_Out' ? `
                    <button class="btn-modal-transfer-out w-full text-left px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg flex items-center gap-3 text-purple-600 dark:text-purple-400">
                        <span class="material-symbols-outlined">logout</span><span>Transfer Out</span>
                    </button>` : ''}` : ''}
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <button class="btn-modal-close w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">Close</button>
                </div>
            </div>`;

        document.body.appendChild(modal);

        modal.querySelector('.btn-modal-view')?.addEventListener('click', () => { this.viewStudent(studentId); modal.remove(); });
        modal.querySelector('.btn-modal-edit')?.addEventListener('click', () => { this.editStudent(studentId); modal.remove(); });
        modal.querySelector('.btn-modal-remarks')?.addEventListener('click', () => { this.addRemarks(studentId); modal.remove(); });
        modal.querySelector('.btn-modal-cancel')?.addEventListener('click', () => { this.cancelEnrollment(studentId); modal.remove(); });
        modal.querySelector('.btn-modal-dropout')?.addEventListener('click', () => { this.markAsDropout(studentId); modal.remove(); });
        modal.querySelector('.btn-modal-transfer-out')?.addEventListener('click', () => { this.transferOut(studentId); modal.remove(); });
        modal.querySelector('.btn-modal-close')?.addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-modal-documents')?.addEventListener('click', () => {
            this.documentHandler.showDocumentChecklist(studentId, student.FullName, student.EnrollmentID);
            modal.remove();
        });
        modal.querySelector('.btn-modal-review-revisions')?.addEventListener('click', () => {
            this.showPendingRevisionsForStudent(studentId);
            modal.remove();
        });
    }

    // ──────────────────────────────────────────────
    // REMARKS
    // ──────────────────────────────────────────────
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
                    <textarea id="remarks-input" rows="4" class="w-full rounded-lg border-gray-300 dark:border-gray-600
                              dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary"
                              placeholder="Enter remarks for this student…"></textarea>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button class="btn-cancel px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">Cancel</button>
                    <button class="btn-save px-4 py-2 bg-primary text-white rounded-lg hover:bg-green-700">Save Remarks</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.querySelector('.btn-cancel').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-save').addEventListener('click', () => {
            const remarks = document.getElementById('remarks-input').value;
            this.saveRemarks(studentId, remarks);
            modal.remove();
        });
    }

    async saveRemarks(studentId, remarks) {
        if (!remarks.trim()) { alert('Please enter remarks'); return; }
        try {
            const r = await fetch('../backend/api/student-update.php?action=add_remarks', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ StudentID: studentId, Remarks: remarks, UserID: this.currentUser.UserID }),
            });
            const d = await r.json();
            d.success ? alert('Remarks saved successfully!') : alert('Failed: ' + d.message);
        } catch (e) { console.error(e); alert('Error saving remarks'); }
    }

    // ──────────────────────────────────────────────
    // STATUS CHANGE MODALS
    // ──────────────────────────────────────────────
    cancelEnrollment(studentId) {
        this.statusChangeModal(studentId, 'Cancelled', 'Cancel Enrollment',
            'Cancel this enrollment? Typically used for administrative cancellations (e.g., enrolled in error).',
            'gray', 'Reason for Cancellation');
    }

    markAsDropout(studentId) {
        this.statusChangeModal(studentId, 'Dropped', 'Mark as Dropout',
            'Mark this student as a dropout? This indicates the student left school after attending.',
            'red', 'Reason for Dropout');
    }

    statusChangeModal(studentId, newStatus, title, desc, color, reasonLabel) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-${color}-600 dark:text-${color}-400">${title}</h2>
                </div>
                <div class="p-6">
                    <p class="text-gray-700 dark:text-gray-300 mb-4">${desc}</p>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">${reasonLabel} <span class="text-red-600">*</span></label>
                    <textarea id="reason-input" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter reason…"></textarea>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button class="btn-no px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300">Cancel</button>
                    <button class="btn-yes px-4 py-2 bg-${color}-600 text-white rounded-lg hover:bg-${color}-700">Confirm</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.querySelector('.btn-no').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-yes').addEventListener('click', () => {
            this.confirmStatusChange(studentId, newStatus, document.getElementById('reason-input').value);
            modal.remove();
        });
    }

    transferOut(studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-purple-600 dark:text-purple-400">Transfer Out Student</h2>
                </div>
                <div class="p-6 space-y-3">
                    <p class="text-gray-700 dark:text-gray-300">Transfer this student to another school?</p>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer To (School Name) <span class="text-red-600">*</span></label>
                        <input type="text" id="transfer-school" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Destination school…"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason <span class="text-red-600">*</span></label>
                        <textarea id="transfer-reason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary" placeholder="Enter reason…"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button class="btn-no px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300">Cancel</button>
                    <button class="btn-yes px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">Confirm Transfer</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.querySelector('.btn-no').addEventListener('click', () => modal.remove());
        modal.querySelector('.btn-yes').addEventListener('click', () => {
            const school = document.getElementById('transfer-school').value.trim();
            const reason = document.getElementById('transfer-reason').value.trim();
            if (!school) { alert('Please enter the destination school name'); return; }
            this.confirmStatusChange(studentId, 'Transferred_Out', reason, school);
            modal.remove();
        });
    }

    async confirmStatusChange(studentId, newStatus, reason, additionalInfo = null) {
        if (!reason?.trim()) { alert('Please provide a reason'); return; }
        try {
            const r = await fetch('../backend/api/student-update.php?action=change_status', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ StudentID: studentId, NewStatus: newStatus, Reason: reason,
                                         UserID: this.currentUser.UserID, AdditionalInfo: additionalInfo }),
            });
            const d = await r.json();
            if (d.success) {
                alert(d.requiresApproval
                    ? `Status change request submitted!\n\nRequest ID: ${d.requestId}`
                    : 'Status updated successfully!');
                this.loadStudents();
            } else {
                alert('Failed: ' + (d.message || 'Unknown error'));
            }
        } catch (e) { console.error(e); alert('Error changing student status'); }
    }

    // ──────────────────────────────────────────────
    // REVISION REQUESTS
    // ──────────────────────────────────────────────
    async showPendingRevisionsForStudent(studentId) {
        try {
            const r = await fetch(`../backend/api/revision-requests.php?action=get_by_student&student_id=${studentId}`);
            const d = await r.json();
            if (!d.success) { alert('Error loading revision requests: ' + d.message); return; }
            const reqs = d.data || [];
            if (!reqs.length) { alert('No pending revision requests found for this student.'); return; }
            this.showRevisionListModal(reqs, studentId);
        } catch (e) { console.error(e); alert('Error loading revision requests'); }
    }

    showRevisionListModal(requests, studentId) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
        const priorityColors = {
            Urgent: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            High:   'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
            Normal: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
        };
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-between z-10">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Pending Revision Requests</h3>
                    <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <span class="material-icons-outlined">close</span>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    ${requests.map(req => `
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors cursor-pointer" data-request-id="${req.RequestID}">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <h4 class="font-semibold text-gray-900 dark:text-white">Request #${req.RequestID}</h4>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium ${priorityColors[req.Priority] || priorityColors.Normal}">${req.Priority}</span>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        ${(req.RequestType||'').replace(/_/g,' ')} • ${new Date(req.CreatedAt).toLocaleDateString()}
                                    </p>
                                </div>
                                <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300">Pending</span>
                            </div>
                            <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">
                                <strong>Requested by:</strong> ${this.esc(req.RequestedByName)} (${this.esc(req.RequesterRole)})
                            </p>
                            <button class="btn-review-request text-sm text-primary hover:text-green-700 dark:text-green-400 font-medium" data-request-id="${req.RequestID}">
                                Review Request →
                            </button>
                        </div>`).join('')}
                </div>
                <div class="sticky bottom-0 bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <button onclick="this.closest('.fixed').remove()" class="w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300">Close</button>
                </div>
            </div>`;
        document.body.appendChild(modal);

        const rh = new RevisionRequestHandler();
        modal.querySelectorAll('.btn-review-request').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                modal.remove();
                rh.showApprovalInterface(e.currentTarget.dataset.requestId);
            });
        });
        modal.querySelectorAll('[data-request-id]').forEach(card => {
            if (!card.classList.contains('btn-review-request')) {
                card.addEventListener('click', e => {
                    modal.remove();
                    rh.showApprovalInterface(e.currentTarget.dataset.requestId);
                });
            }
        });
    }

    // ──────────────────────────────────────────────
    // PAGINATION
    // ──────────────────────────────────────────────
    updatePagination() {
        const total = Math.ceil(this.filteredStudents.length / this.itemsPerPage);
        const start = (this.currentPage - 1) * this.itemsPerPage + 1;
        const end   = Math.min(start + this.itemsPerPage - 1, this.filteredStudents.length);

        const pText = document.querySelector('nav[aria-label="Pagination"] p');
        if (pText) pText.innerHTML = `Showing <span class="font-medium">${this.filteredStudents.length > 0 ? start : 0}</span>
            to <span class="font-medium">${end}</span> of <span class="font-medium">${this.filteredStudents.length}</span> results`;

        document.querySelectorAll('nav[aria-label="Pagination"] a').forEach(a => {
            const prev = a.textContent.trim() === 'Previous';
            const next = a.textContent.trim() === 'Next';
            if (prev) {
                a.onclick = e => { e.preventDefault(); if (this.currentPage > 1) { this.currentPage--; this.renderTable(); this.updatePagination(); } };
                a.classList.toggle('opacity-50', this.currentPage === 1);
                a.style.pointerEvents = this.currentPage === 1 ? 'none' : 'auto';
            }
            if (next) {
                a.onclick = e => { e.preventDefault(); if (this.currentPage < total) { this.currentPage++; this.renderTable(); this.updatePagination(); } };
                a.classList.toggle('opacity-50', this.currentPage >= total || total === 0);
                a.style.pointerEvents = (this.currentPage >= total || total === 0) ? 'none' : 'auto';
            }
        });
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────
    addNewStudent() { window.location.href = 'enrollment-management.html'; }

    formatName(fullName) {
        if (!fullName) return 'N/A';
        const parts = fullName.split(',').map(p => p.trim());
        return parts.length === 2 ? `${parts[1]} ${parts[0]}` : fullName;
    }

    extractGradeNumber(gradeLevel) {
        if (!gradeLevel) return 'N/A';
        const m = gradeLevel.match(/\d+/);
        return m ? `Grade ${m[0]}` : gradeLevel;
    }

    esc(text) {
        const d = document.createElement('div');
        d.textContent = text ?? '';
        return d.innerHTML;
    }

    showLoading(show) { console.log('Loading:', show); }
}

// ── Global init ────────────────────────────────────────────────────
let studentManagement;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { studentManagement = new StudentManagementHandler(); });
} else {
    studentManagement = new StudentManagementHandler();
}
window.studentManagement = studentManagement;