// =====================================================
// Enrollment Review Handler — djihs_enrollment_v2 schema
// File: js/enrollment-review.js
//
// FIXES vs old version:
//  1. updateStats()      — now calls ?action=stats API (was hardcoded 0)
//  2. renderTable()      — uses EnrollmentType (not LearnerType, removed)
//  3. badge classes      — getEnrollmentTypeBadgeClass() replaces getLearnerTypeBadgeClass()
//  4. showDetailsModal() — parent/guardian data read from data.guardians{}
//                          (keyed by RelationshipType: Father | Mother | Guardian)
//                          NOT from flat data.FatherFirstName etc. (not in student table)
//  5. showDetailsModal() — Age uses TIMESTAMPDIFF result from PHP (data.Age)
//  6. showDetailsModal() — Weight/Height section REMOVED (not in schema)
//  7. showDetailsModal() — "Learner Type" label removed; "Enrollment Type" is the one field
//  8. showDetailsModal() — documents checklist rendered from data.documents[]
// =====================================================

class EnrollmentReviewHandler {
    constructor() {
        this.currentUser        = null;
        this.enrollments        = [];
        this.filteredEnrollments = [];
        this.selectedEnrollment = null;
        this.init();
    }

    // ──────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────
    init() {
        this.currentUser = this.getCurrentUser();
        if (!this.currentUser) {
            alert('You must be logged in to access this page.');
            window.location.href = '../login.html';
            return;
        }

        this.bindEventListeners();
        this.loadStats();
        this.loadPendingEnrollments();
    }

    getCurrentUser() {
        try { return JSON.parse(localStorage.getItem('user') || 'null'); }
        catch { return null; }
    }

    // ──────────────────────────────────────────────
    // EVENT LISTENERS
    // ──────────────────────────────────────────────
    bindEventListeners() {
        document.getElementById('btnRefresh')
            ?.addEventListener('click', () => {
                this.loadStats();
                this.loadPendingEnrollments();
            });

        document.getElementById('btnApproveAll')
            ?.addEventListener('click', () => this.approveAllEnrollments());

        document.getElementById('filterGrade')
            ?.addEventListener('change', () => this.applyFilters());

        document.getElementById('filterStrand')
            ?.addEventListener('change', () => this.applyFilters());

        document.getElementById('closeModal')
            ?.addEventListener('click', () => this.closeModal());
        document.getElementById('btnCloseModal')
            ?.addEventListener('click', () => this.closeModal());

        document.getElementById('btnApprove')
            ?.addEventListener('click', () => this.approveEnrollment());
        document.getElementById('btnReject')
            ?.addEventListener('click', () => this.rejectEnrollment());
    }

    // ──────────────────────────────────────────────
    // LOAD STATS — calls ?action=stats
    // Fills: pendingCount, approvedCount (confirmedToday), totalCount (totalConfirmed)
    // ──────────────────────────────────────────────
    async loadStats() {
        try {
            const res  = await fetch('../backend/api/enrollment.php?action=stats');
            const data = await res.json();
            if (!data.success) return;

            const s = data.data;
            this.setText('pendingCount',  s.pendingCount);
            this.setText('approvedCount', s.confirmedToday);
            this.setText('totalCount',    s.totalConfirmed);

        } catch (err) {
            console.error('Error loading stats:', err);
        }
    }

    // ──────────────────────────────────────────────
    // LOAD PENDING ENROLLMENTS
    // ──────────────────────────────────────────────
    async loadPendingEnrollments() {
        try {
            this.showLoading(true);

            const res    = await fetch('../backend/api/enrollment.php?action=pending');
            const result = await res.json();

            if (!result.success) throw new Error(result.message || 'Failed to load enrollments');

            this.enrollments         = result.data || [];
            this.filteredEnrollments = [...this.enrollments];
            this.renderTable();

        } catch (err) {
            console.error('Error loading enrollments:', err);
            this.showToast('Error loading enrollments: ' + err.message, 'error');
        } finally {
            this.showLoading(false);
        }
    }

    // ──────────────────────────────────────────────
    // FILTERS
    // ──────────────────────────────────────────────
    applyFilters() {
        const gradeFilter  = document.getElementById('filterGrade')?.value  || '';
        const strandFilter = document.getElementById('filterStrand')?.value || '';

        this.filteredEnrollments = this.enrollments.filter(e => {
            if (gradeFilter  && e.GradeLevelName !== gradeFilter)             return false;
            if (strandFilter && !e.StrandName?.includes(strandFilter))        return false;
            return true;
        });

        this.renderTable();
    }

    // ──────────────────────────────────────────────
    // RENDER TABLE
    // Uses EnrollmentType — LearnerType is REMOVED from schema
    // ──────────────────────────────────────────────
    renderTable() {
        const tbody      = document.getElementById('enrollmentsBody');
        const emptyState = document.getElementById('emptyState');
        const table      = document.getElementById('enrollmentsTable');

        if (this.filteredEnrollments.length === 0) {
            emptyState.classList.remove('hidden');
            table.classList.add('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        table.classList.remove('hidden');

        tbody.innerHTML = this.filteredEnrollments.map(e => `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center
                                    text-primary font-semibold text-sm shrink-0">
                            ${this.getInitials(e.StudentName)}
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                ${this.escHtml(e.StudentName)}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                ${this.escHtml(e.AcademicYear)}
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                    ${this.escHtml(e.LRN || 'N/A')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                    ${this.escHtml(e.GradeLevelName)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                    ${this.escHtml(e.StrandName || '—')}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                 ${this.getEnrollmentTypeBadgeClass(e.EnrollmentType)}">
                        ${this.formatEnrollmentType(e.EnrollmentType)}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                    ${this.formatDate(e.EnrollmentDate)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <button onclick="enrollmentReview.viewDetails(${e.EnrollmentID})"
                            class="inline-flex items-center gap-1 text-primary hover:text-primary/80 font-medium">
                        <span class="material-icons-outlined text-[18px]">visibility</span>
                        View
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // ──────────────────────────────────────────────
    // VIEW DETAILS — loads single enrollment then opens modal
    // ──────────────────────────────────────────────
    async viewDetails(enrollmentID) {
        try {
            const res    = await fetch(`../backend/api/enrollment.php?action=details&id=${enrollmentID}`);
            const result = await res.json();

            if (!result.success || !result.data)
                throw new Error(result.message || 'Failed to load details');

            this.selectedEnrollment = result.data;
            this.showDetailsModal(result.data);

        } catch (err) {
            console.error('Error loading details:', err);
            this.showToast('Error loading enrollment details: ' + err.message, 'error');
        }
    }

    // ──────────────────────────────────────────────
    // DETAILS MODAL
    //
    // Guardian data comes from data.guardians — a map keyed by RelationshipType:
    //   data.guardians.Father   → { LastName, FirstName, MiddleName, ContactNumber }
    //   data.guardians.Mother   → { ... }
    //   data.guardians.Guardian → { ..., GuardianRelationship }
    //
    // Age: computed server-side via TIMESTAMPDIFF, returned as data.Age
    // Weight / Height: NOT rendered — removed from schema
    // LearnerType: NOT rendered — field removed; EnrollmentType is the only type field
    // ──────────────────────────────────────────────
    showDetailsModal(d) {
        const modal   = document.getElementById('detailsModal');
        const content = document.getElementById('modalContent');

        // ── Helper: format guardian name ──────────────────────────
        const guardianName = (g) => {
            if (!g) return null;
            return [g.LastName, g.FirstName, g.MiddleName].filter(Boolean).join(', ');
        };

        const father   = d.guardians?.Father;
        const mother   = d.guardians?.Mother;
        const guardian = d.guardians?.Guardian;

        // ── Compute contact display (student.ContactNumber) ───────
        const contactDisplay = d.ContactNumber || 'Not provided';

        // ── Status badge ──────────────────────────────────────────
        const statusColors = {
            Pending:          'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
            Confirmed:        'bg-green-100  text-green-800  dark:bg-green-900/30  dark:text-green-300',
            Cancelled:        'bg-red-100    text-red-800    dark:bg-red-900/30    dark:text-red-300',
            For_Review:       'bg-blue-100   text-blue-800   dark:bg-blue-900/30   dark:text-blue-300',
            Dropped:          'bg-gray-100   text-gray-800   dark:bg-gray-800      dark:text-gray-300',
            Transferred_Out:  'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
        };
        const statusCls = statusColors[d.Status] || statusColors.Pending;

        // ── Documents checklist ───────────────────────────────────
        const docLabels = {
            PSA_Birth_Cert:   'PSA Birth Certificate',
            Local_Birth_Cert: 'Local Birth Certificate',
            Report_Card:      'Report Card / Card 138',
            Form_137:         'Form 137',
            Good_Moral:       'Good Moral Certificate',
            Transfer_Cert:    'Certificate of Transfer',
        };

        const docsHTML = (d.documents && d.documents.length > 0)
            ? `<div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">folder_open</span>
                    Document Checklist
                </h4>
                <div class="space-y-2">
                    ${d.documents.map(doc => `
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-700 dark:text-gray-300">
                                ${this.escHtml(docLabels[doc.DocumentType] || doc.DocumentType)}
                            </span>
                            <div class="flex gap-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    ${doc.IsSubmitted == 1
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                                        : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'}">
                                    ${doc.IsSubmitted == 1 ? '✓ Submitted' : 'Not submitted'}
                                </span>
                                ${doc.IsSubmitted == 1 ? `
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    ${doc.IsVerified == 1
                                        ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300'
                                        : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300'}">
                                    ${doc.IsVerified == 1 ? '✓ Verified' : 'Pending verify'}
                                </span>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
               </div>`
            : '';

        // ── Parent/Guardian section ───────────────────────────────
        const guardianRows = [
            father   ? { label: "Father's Name",   name: guardianName(father),   contact: father.ContactNumber,   rel: null                        } : null,
            mother   ? { label: "Mother's Name",    name: guardianName(mother),   contact: mother.ContactNumber,   rel: null                        } : null,
            guardian ? { label: "Legal Guardian",   name: guardianName(guardian), contact: guardian.ContactNumber, rel: guardian.GuardianRelationship} : null,
        ].filter(Boolean);

        const guardiansHTML = guardianRows.length > 0
            ? guardianRows.map(g => `
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">${g.label}</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                        ${this.escHtml(g.name || '—')}
                        ${g.rel ? `<span class="text-xs text-gray-500">(${this.escHtml(g.rel)})</span>` : ''}
                    </p>
                    ${g.contact ? `<p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${this.escHtml(g.contact)}</p>` : ''}
                </div>`).join('')
            : `<p class="text-sm text-gray-500 dark:text-gray-400 italic">No parent/guardian information on record.</p>`;

        // ── Special conditions ────────────────────────────────────
        const specialHTML = (d.IsIPCommunity == 1 || d.IsPWD == 1 || d.Is4PsBeneficiary == 1)
            ? `<div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4 border border-yellow-200 dark:border-yellow-800">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                    <span class="material-icons-outlined text-warning">info</span>
                    Special Considerations
                </h4>
                ${d.IsIPCommunity == 1 ? `
                    <p class="text-sm text-gray-900 dark:text-white mb-1">
                        <strong>IP Community Member:</strong>
                        ${this.escHtml(d.IPCommunitySpecify || 'Yes')}
                    </p>` : ''}
                ${d.IsPWD == 1 ? `
                    <p class="text-sm text-gray-900 dark:text-white mb-1">
                        <strong>Person with Disability:</strong>
                        ${this.escHtml(d.PWDSpecify || 'Yes')}
                    </p>` : ''}
                ${d.Is4PsBeneficiary == 1 ? `
                    <p class="text-sm text-gray-900 dark:text-white">
                        <strong>4Ps Beneficiary:</strong> Yes
                    </p>` : ''}
               </div>`
            : '';

        // ── Remarks (if any) ──────────────────────────────────────
        const remarksHTML = d.Remarks
            ? `<div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-2 flex items-center gap-2">
                    <span class="material-icons-outlined text-blue-500">notes</span>
                    Remarks
                </h4>
                <p class="text-sm text-gray-700 dark:text-gray-300">${this.escHtml(d.Remarks)}</p>
               </div>`
            : '';

        // ── Assemble modal ────────────────────────────────────────
        content.innerHTML = `
        <div class="space-y-5">

            <!-- Student Information -->
            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">person</span>
                    Student Information
                </h4>
                <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Full Name</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(d.FullName)}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">LRN</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white font-mono">
                            ${this.escHtml(d.LRN || 'Not provided')}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Date of Birth</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.formatDate(d.DateOfBirth)}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Age</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${d.Age != null ? d.Age + ' years old' : '—'}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Gender</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(d.Gender || '—')}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Religion</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(d.Religion || 'Not specified')}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Mother Tongue</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(d.MotherTongue || '—')}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Contact Number</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(contactDisplay)}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Enrollment Information -->
            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">school</span>
                    Enrollment Information
                </h4>
                <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">School Year</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(d.AcademicYear)}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Grade Level</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.escHtml(d.GradeLevelName)}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Strand / Track</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${d.StrandName
                                ? `${this.escHtml(d.StrandName)}
                                   <span class="text-xs text-gray-400">(${this.escHtml(d.StrandCode)})</span>`
                                : '<span class="text-gray-400">N/A — Junior High</span>'}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Enrollment Type</p>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                     ${this.getEnrollmentTypeBadgeClass(d.EnrollmentType)}">
                            ${this.formatEnrollmentType(d.EnrollmentType)}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Enrollment Date</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            ${this.formatDate(d.EnrollmentDate)}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${statusCls}">
                            ${this.escHtml(d.Status)}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">home</span>
                    Address
                </h4>
                <p class="text-sm text-gray-900 dark:text-white">
                    ${this.escHtml(d.CompleteAddress || '—')}
                </p>
            </div>

            <!-- Parent / Guardian Information -->
            <!-- Data from parentguardian table (RelationshipType: Father | Mother | Guardian) -->
            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">family_restroom</span>
                    Parent / Guardian Information
                </h4>
                <div class="space-y-3">
                    ${guardiansHTML}
                </div>
            </div>

            <!-- Special Conditions (IP, PWD, 4Ps) -->
            ${specialHTML}

            <!-- Document Checklist (only after approval) -->
            ${docsHTML}

            <!-- Remarks -->
            ${remarksHTML}

        </div>`;

        modal.classList.remove('hidden');
    }

    closeModal() {
        document.getElementById('detailsModal').classList.add('hidden');
        this.selectedEnrollment = null;
    }

    // ──────────────────────────────────────────────
    // APPROVE (single)
    // ──────────────────────────────────────────────
    async approveEnrollment() {
        if (!this.selectedEnrollment) return;

        if (!confirm(`Approve enrollment for ${this.selectedEnrollment.FullName}?`)) return;

        try {
            const res    = await fetch('../backend/api/enrollment.php?action=approve', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    enrollmentID: this.selectedEnrollment.EnrollmentID,
                    reviewerID:   this.currentUser.UserID,
                }),
            });
            const result = await res.json();
            if (!result.success) throw new Error(result.message);

            this.showToast('✓ Enrollment approved successfully!', 'success');
            this.closeModal();
            this.loadStats();
            this.loadPendingEnrollments();

        } catch (err) {
            console.error('Approve error:', err);
            this.showToast('Error: ' + err.message, 'error');
        }
    }

    // ──────────────────────────────────────────────
    // APPROVE ALL (filtered list)
    // ──────────────────────────────────────────────
    async approveAllEnrollments() {
        const pending = this.filteredEnrollments.filter(e => e.Status === 'Pending');
        if (!pending.length) { alert('No pending enrollments to approve.'); return; }

        if (!confirm(`Approve all ${pending.length} pending enrollment${pending.length > 1 ? 's' : ''}?\n\nThis cannot be undone.`)) return;

        const btn = document.getElementById('btnApproveAll');
        const origHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="material-icons-outlined text-[18px] animate-spin">sync</span> Processing…';

        let ok = 0, fail = 0;

        for (const e of pending) {
            try {
                const res    = await fetch('../backend/api/enrollment.php?action=approve', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ enrollmentID: e.EnrollmentID, reviewerID: this.currentUser.UserID }),
                });
                const result = await res.json();
                result.success ? ok++ : fail++;
            } catch { fail++; }
        }

        btn.disabled = false;
        btn.innerHTML = origHTML;

        const msg = `✓ Approved ${ok} enrollment${ok !== 1 ? 's' : ''}.`
            + (fail > 0 ? `\n⚠ Failed: ${fail}.` : '');
        alert(msg);

        this.loadStats();
        this.loadPendingEnrollments();
    }

    // ──────────────────────────────────────────────
    // REJECT
    // ──────────────────────────────────────────────
    async rejectEnrollment() {
        if (!this.selectedEnrollment) return;

        const reason = prompt(`Reject enrollment for ${this.selectedEnrollment.FullName}?\n\nPlease provide a reason:`);
        if (!reason?.trim()) return;

        try {
            const res    = await fetch('../backend/api/enrollment.php?action=reject', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    enrollmentID: this.selectedEnrollment.EnrollmentID,
                    reviewerID:   this.currentUser.UserID,
                    reason:       reason.trim(),
                }),
            });
            const result = await res.json();
            if (!result.success) throw new Error(result.message);

            this.showToast('Enrollment rejected.', 'info');
            this.closeModal();
            this.loadStats();
            this.loadPendingEnrollments();

        } catch (err) {
            console.error('Reject error:', err);
            this.showToast('Error: ' + err.message, 'error');
        }
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────

    // EnrollmentType badge — Regular = green, everything else = yellow/amber
    getEnrollmentTypeBadgeClass(type) {
        if (!type) return 'bg-gray-100 text-gray-600';
        if (type.startsWith('Regular')) {
            return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
        }
        if (type === 'Transferee') {
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
        }
        if (type === 'ALS') {
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300';
        }
        // Late | Balik_Aral | Repeater
        return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
    }

    // Convert enum value → readable label
    formatEnrollmentType(type) {
        if (!type) return '—';
        const labels = {
            Regular_Old_Student: 'Regular – Old',
            Regular_New_Student: 'Regular – New',
            Late:        'Late Enrollee',
            Transferee:  'Transferee',
            Balik_Aral:  'Balik-Aral',
            Repeater:    'Repeater',
            ALS:         'ALS',
        };
        return labels[type] || type.replace(/_/g, ' ');
    }

    getInitials(name) {
        if (!name) return '?';
        const parts = name.split(',').map(p => p.trim());
        if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
        return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
    }

    formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        return isNaN(d) ? dateStr : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    escHtml(text) {
        const d = document.createElement('div');
        d.textContent = text ?? '';
        return d.innerHTML;
    }

    setText(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val ?? 0;
    }

    showLoading(show) {
        document.getElementById('loadingState')?.classList.toggle('hidden', !show);
    }

    // Toast notification (top-right)
    showToast(message, type = 'info') {
        const colors = {
            success: 'bg-green-600',
            error:   'bg-red-600',
            info:    'bg-blue-600',
        };
        const toast = document.createElement('div');
        toast.className = `fixed top-5 right-5 z-[9999] px-5 py-3 rounded-lg shadow-lg text-white text-sm
                           font-medium transition-all duration-300 ${colors[type] || colors.info}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
}

// ── Global init ────────────────────────────────────────────────────
let enrollmentReview;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        enrollmentReview = new EnrollmentReviewHandler();
    });
} else {
    enrollmentReview = new EnrollmentReviewHandler();
}