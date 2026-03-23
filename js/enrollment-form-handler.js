// =====================================================
// Enrollment Form Handler — aligned with djihs_enrollment_v2 schema
// File: js/enrollment-form-handler.js
// =====================================================

class EnrollmentFormHandler {
    constructor() {
        this.currentUser           = null;
        this.submitBtn             = null;
        this.lrnSearchTimeout      = null;
        this.studentEnrollmentHistory = [];
        this.latestEnrollment      = null;
        this.guardianMap           = {};   // keyed by Father / Mother / Guardian
        this.activeAcademicYear    = null; // { AcademicYearID, YearLabel }
        this.init();
    }

    // ──────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────
    async init() {
        this.currentUser = this.getCurrentUser();
        if (!this.currentUser) {
            alert('You must be logged in to access this page.');
            window.location.href = '../login.html';
            return;
        }

        await this.loadActiveAcademicYear();
        await this.loadStrands();
        this.bindEventListeners();
        this.setupAutoCalculateAge();
        this.setupTrackVisibility();
        this.setupLRNAutofill();
    }

    getCurrentUser() {
        try {
            return JSON.parse(localStorage.getItem('user') || 'null');
        } catch {
            return null;
        }
    }

    // ──────────────────────────────────────────────
    // LOAD ACTIVE ACADEMIC YEAR
    // Pre-fills the school year field and stores the AcademicYearID
    // ──────────────────────────────────────────────
    async loadActiveAcademicYear() {
        try {
            const res  = await fetch('../backend/api/academic-year.php?action=active');
            const data = await res.json();
            if (data.success && data.academicYear) {
                this.activeAcademicYear = data.academicYear; // { AcademicYearID, YearLabel }
                const syInput = document.getElementById('schoolYear');
                if (syInput) {
                    syInput.value    = data.academicYear.YearLabel;
                    syInput.readOnly = true; // driven by DB
                }
            }
        } catch (err) {
            console.warn('Could not load active academic year:', err);
        }
    }

    // ──────────────────────────────────────────────
    // LOAD STRANDS (dynamic from DB)
    // ──────────────────────────────────────────────
    async loadStrands() {
        try {
            const res  = await fetch('../backend/api/manage-strands.php?action=list');
            const data = await res.json();
            if (!data.success) return;

            const academic = data.strands.filter(s => s.StrandCategory === 'Academic' && s.IsActive == 1);
            const tvl      = data.strands.filter(s => s.StrandCategory === 'TVL'      && s.IsActive == 1);

            this.renderStrandOptions(academic, 'academicStrandsContainer');
            this.renderStrandOptions(tvl,      'tvlStrandsContainer');
        } catch (err) {
            console.error('Error loading strands:', err);
        }
    }

    renderStrandOptions(strands, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = strands.map(s => `
            <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                <input class="form-radio text-primary focus:ring-primary/50"
                    name="track" type="radio" value="${s.StrandID}"/>
                ${s.StrandCode}
            </label>
        `).join('');
    }

    // ──────────────────────────────────────────────
    // EVENT LISTENERS
    // ──────────────────────────────────────────────
    bindEventListeners() {
        // Submit button (in sticky footer)
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            this.submitBtn = submitBtn;
            submitBtn.addEventListener('click', e => this.handleSubmit(e));
        }

        // Reset button
        const resetBtn = document.getElementById('resetBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', e => this.handleReset(e));
        }

        // Grade-level select → show/hide strand section
        const gradeSelect = document.querySelectorAll('select')[0];
        if (gradeSelect) {
            gradeSelect.addEventListener('change', e => this.handleGradeLevelChange(e));
        }
    }

    // ──────────────────────────────────────────────
    // LRN FORMAT VALIDATION
    //
    // Rules based on DepEd LRN format specs:
    //   K-12  : XXXXXXYYZZZZ  (12 digits)
    //     digits 1    : school type (1–6)
    //     digits 7–8  : enrollment year YY (plausible range: 90–currentYY)
    //   ALS   : starts with 5 (also 12 digits)
    //
    // Returns { valid: true, isALS: bool }
    //      or { valid: false, message: string }
    //
    // Intentionally NOT validating the sequential student number
    // (ZZZZ / ZZZZZ) — no local reference available, over-validation
    // would reject legitimate legacy LRNs.
    // ──────────────────────────────────────────────
    validateLRNFormat(lrn) {
        // Must be exactly 12 digits
        if (!/^\d{12}$/.test(lrn)) {
            return { valid: false, message: 'LRN must be exactly 12 digits.' };
        }

        // First digit must be 1–6 (school type prefix)
        const firstDigit = parseInt(lrn[0], 10);
        if (firstDigit < 1 || firstDigit > 6) {
            return {
                valid: false,
                message: 'Invalid LRN: first digit must be 1–6 (school type prefix).'
            };
        }

        // Digits 7–8 represent the enrollment year (YY).
        // Valid range: 90 (1990) up to the current year's last two digits.
        const yy        = parseInt(lrn.slice(6, 8), 10);
        const currentYY = new Date().getFullYear() % 100;
        if (yy < 90 || yy > currentYY) {
            return {
                valid: false,
                message: `LRN enrollment year digits (${String(yy).padStart(2,'0')}) seem invalid. Expected 90–${currentYY}.`
            };
        }

        // ALS LRNs start with 5
        const isALS = lrn.startsWith('5');

        return { valid: true, isALS };
    }

    // ──────────────────────────────────────────────
    // LRN FORMAT BADGE
    // Shows a small inline tag next to the LRN input indicating
    // the detected learner category (K-12 or ALS).
    // ──────────────────────────────────────────────
    showLRNFormatBadge(isALS) {
        this.clearLRNFormatBadge();

        const lrnInput = document.getElementById('lrn');
        if (!lrnInput) return;

        const badge = document.createElement('span');
        badge.id    = 'lrnFormatBadge';

        if (isALS) {
            badge.className   = 'inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300 ml-2';
            badge.textContent = 'ALS Learner';
        } else {
            badge.className   = 'inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 ml-2';
            badge.textContent = 'K–12 Learner';
        }

        // Insert badge after the input (or after its wrapper label if present)
        lrnInput.insertAdjacentElement('afterend', badge);
    }

    clearLRNFormatBadge() {
        document.getElementById('lrnFormatBadge')?.remove();
    }

    // ──────────────────────────────────────────────
    // LRN AUTO-FILL
    // ──────────────────────────────────────────────
    setupLRNAutofill() {
        const lrnInput = document.getElementById('lrn');
        if (!lrnInput) return;

        // Loading spinner
        const spinner = document.createElement('div');
        spinner.id = 'lrnSpinner';
        spinner.className = 'hidden absolute right-3 top-1/2 -translate-y-1/2';
        spinner.innerHTML = '<div class="loading-spinner w-5 h-5"></div>';
        lrnInput.parentElement.style.position = 'relative';
        lrnInput.parentElement.appendChild(spinner);

        lrnInput.addEventListener('input', e => {
            const val = e.target.value.trim();
            clearTimeout(this.lrnSearchTimeout);

            // Clear badge and any previous format notification on every keystroke
            this.clearLRNFormatBadge();
            this.clearLRNFormatNotification();

            if (val.length === 0) {
                this.clearLRNResults();
                return;
            }

            // Run format validation once we have a full 12-digit value
            if (val.length === 12) {
                const check = this.validateLRNFormat(val);

                if (!check.valid) {
                    // Show inline format error — do NOT fire the API lookup
                    this.showLRNFormatNotification(check.message, 'warning');
                    this.clearLRNResults();
                    return;
                }

                // Format is valid — show category badge and proceed to API lookup
                this.showLRNFormatBadge(check.isALS);
                this.lrnSearchTimeout = setTimeout(() => this.searchStudentByLRN(val), 500);

            } else if (val.length > 12) {
                // Typed beyond 12 digits
                this.showLRNFormatNotification('LRN must be exactly 12 digits.', 'warning');
                this.clearLRNResults();
            }
            // While still typing (< 12 digits), stay silent — no error yet
        });
    }

    async searchStudentByLRN(lrn) {
        const spinner = document.getElementById('lrnSpinner');
        if (spinner) spinner.classList.remove('hidden');

        try {
            const res    = await fetch(`../backend/api/student-by-lrn.php?lrn=${lrn}`);
            const result = await res.json();

            if (result.success && result.student) {
                this.studentEnrollmentHistory = result.enrollmentHistory || [];
                this.latestEnrollment         = result.latestEnrollment  || null;
                this.guardianMap              = result.guardians          || {};

                this.autofillStudentData(result.student);
                this.autofillGuardianData(this.guardianMap);
                this.displayEnrollmentHistory();
                this.showNotification('Student found! Form auto-filled with existing data.', 'success');
            } else {
                this.clearLRNResults();
                this.showNotification('LRN not found in system. Please enter student details manually.', 'info');
            }
        } catch (err) {
            console.error('LRN lookup error:', err);
            this.showNotification('Error searching for student. Please try again.', 'error');
        } finally {
            if (spinner) spinner.classList.add('hidden');
        }
    }

    clearLRNResults() {
        this.studentEnrollmentHistory = [];
        this.latestEnrollment         = null;
        this.guardianMap              = {};
        this.removeEnrollmentHistoryDisplay();
    }

    // ──────────────────────────────────────────────
    // LRN FORMAT NOTIFICATION (inline warning, separate from the
    // main lrnNotification banner used for API results)
    // ──────────────────────────────────────────────
    showLRNFormatNotification(message, type = 'warning') {
        this.clearLRNFormatNotification();

        const colors = {
            warning: { bg: 'bg-amber-50',  border: 'border-amber-200', text: 'text-amber-800', icon: 'warning' },
            error:   { bg: 'bg-red-50',    border: 'border-red-200',   text: 'text-red-800',   icon: 'error'   },
        };
        const c = colors[type] || colors.warning;

        const notif = document.createElement('div');
        notif.id = 'lrnFormatNotification';
        notif.className = `${c.bg} ${c.border} ${c.text} border rounded-lg p-3 mt-2 flex items-center gap-2 text-sm`;
        notif.innerHTML = `
            <span class="material-icons-outlined text-[18px] shrink-0">${c.icon}</span>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 shrink-0">
                <span class="material-icons-outlined text-[18px]">close</span>
            </button>
        `;

        const lrnInput = document.getElementById('lrn');
        if (lrnInput) {
            lrnInput.parentElement.insertAdjacentElement('afterend', notif);
        }
    }

    clearLRNFormatNotification() {
        document.getElementById('lrnFormatNotification')?.remove();
    }

    // ──────────────────────────────────────────────
    // AUTO-FILL FORM
    // ──────────────────────────────────────────────
    autofillStudentData(s) {
        this.setVal('lastName',          s.LastName);
        this.setVal('firstName',         s.FirstName);
        this.setVal('middleName',        s.MiddleName);
        this.setVal('extensionName',     s.ExtensionName);
        this.setVal('birthdate',         s.DateOfBirth);   // 'YYYY-MM-DD' from DB
        this.setVal('religion',          s.Religion);
        this.setVal('motherTongue',      s.MotherTongue);
        this.setVal('barangay',          s.Barangay);
        this.setVal('municipality',      s.Municipality);
        this.setVal('province',          s.Province);
        this.setVal('houseNumber',       s.HouseNumber);
        this.setVal('sitioStreet',       s.SitioStreet);
        this.setVal('contactNumber',     s.ContactNumber);

        // Age (derived)
        if (s.Age) {
            const ageInput = document.querySelector('input[placeholder*="16"]');
            if (ageInput) ageInput.value = s.Age;
        }

        // Gender select (second <select>)
        const sexSelect = document.querySelectorAll('select')[1];
        if (sexSelect && s.Gender) sexSelect.value = s.Gender;

        // Boolean radios
        this.setRadio('isIPCommunity',    s.IsIPCommunity   ? '1' : '0');
        this.setRadio('isPWD',            s.IsPWD           ? '1' : '0');
        this.setRadio('is4PsBeneficiary', s.Is4PsBeneficiary ? '1' : '0');

        // Trigger age recalculation
        const bdInput = document.getElementById('birthdate');
        if (bdInput) bdInput.dispatchEvent(new Event('change'));
    }

    autofillGuardianData(guardianMap) {
        // Father
        const f = guardianMap['Father'] || {};
        this.setVal('fatherLastName',   f.LastName);
        this.setVal('fatherFirstName',  f.FirstName);
        this.setVal('fatherMiddleName', f.MiddleName);

        // Mother
        const m = guardianMap['Mother'] || {};
        this.setVal('motherLastName',   m.LastName);
        this.setVal('motherFirstName',  m.FirstName);
        this.setVal('motherMiddleName', m.MiddleName);

        // Guardian
        const g = guardianMap['Guardian'] || {};
        this.setVal('guardianLastName',   g.LastName);
        this.setVal('guardianFirstName',  g.FirstName);
        this.setVal('guardianMiddleName', g.MiddleName);
    }

    // ──────────────────────────────────────────────
    // ENROLLMENT HISTORY DISPLAY
    // ──────────────────────────────────────────────
    displayEnrollmentHistory() {
        this.removeEnrollmentHistoryDisplay();
        if (!this.studentEnrollmentHistory.length) return;

        const div = document.createElement('div');
        div.id = 'enrollmentHistoryDisplay';
        div.className = 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4';

        const rows = this.studentEnrollmentHistory.map((e, i) => {
            const statusColor = {
                Confirmed:    'text-green-600',
                Pending:      'text-yellow-600',
                Cancelled:    'text-red-600',
                For_Review:   'text-blue-600',
                Dropped:      'text-gray-500',
                Transferred_Out: 'text-gray-500',
            }[e.Status] || 'text-gray-600';

            return `
                <div class="bg-white dark:bg-slate-800 rounded-lg p-3 ${i === 0 ? 'border-2 border-blue-400' : ''}">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-semibold text-slate-800 dark:text-white">${e.AcademicYear}</span>
                                ${i === 0 ? '<span class="text-xs bg-blue-500 text-white px-2 py-0.5 rounded-full">Latest</span>' : ''}
                            </div>
                            <div class="text-sm text-slate-600 dark:text-slate-300">
                                <span class="font-medium">${e.GradeLevelName}</span>
                                ${e.StrandName ? ` — ${e.StrandName}` : ''}
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                ${e.EnrollmentType.replace(/_/g, ' ')}
                            </div>
                        </div>
                        <span class="text-xs font-semibold ${statusColor}">${e.Status}</span>
                    </div>
                </div>
            `;
        }).join('');

        div.innerHTML = `
            <h4 class="text-sm font-semibold text-blue-900 dark:text-blue-100 mb-3 flex items-center gap-2">
                <span class="material-icons-outlined text-[20px]">history_edu</span>
                Enrollment History
            </h4>
            <div class="space-y-2">${rows}</div>
        `;

        const header = document.querySelector('.flex.flex-col.sm\\:flex-row.sm\\:items-center');
        const notif  = document.getElementById('lrnNotification');
        const anchor = notif || header;
        if (anchor) anchor.insertAdjacentElement('afterend', div);
    }

    removeEnrollmentHistoryDisplay() {
        document.getElementById('enrollmentHistoryDisplay')?.remove();
    }

    // ──────────────────────────────────────────────
    // NOTIFICATION BANNER
    // ──────────────────────────────────────────────
    showNotification(message, type = 'info') {
        document.getElementById('lrnNotification')?.remove();

        const colors = {
            success: { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-800', icon: 'check_circle' },
            error:   { bg: 'bg-red-50',   border: 'border-red-200',   text: 'text-red-800',   icon: 'error'       },
            info:    { bg: 'bg-blue-50',  border: 'border-blue-200',  text: 'text-blue-800',  icon: 'info'        },
        };
        const c = colors[type] || colors.info;

        const notif = document.createElement('div');
        notif.id = 'lrnNotification';
        notif.className = `${c.bg} ${c.border} ${c.text} border rounded-lg p-4 mb-4 flex items-center gap-3`;
        notif.innerHTML = `
            <span class="material-icons-outlined">${c.icon}</span>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
                <span class="material-icons-outlined text-[20px]">close</span>
            </button>
        `;

        const header = document.querySelector('.flex.flex-col.sm\\:flex-row.sm\\:items-center');
        if (header) {
            header.insertAdjacentElement('afterend', notif);
            setTimeout(() => { notif.style.opacity = '0'; setTimeout(() => notif.remove(), 300); }, 5000);
        }
    }

    // ──────────────────────────────────────────────
    // AGE AUTO-CALCULATE
    // ──────────────────────────────────────────────
    setupAutoCalculateAge() {
        const bd  = document.getElementById('birthdate') || document.querySelector('input[type="date"]');
        const age = document.querySelector('input[placeholder*="16"]');
        if (!bd || !age) return;

        bd.addEventListener('change', () => {
            if (!bd.value) return;
            const dob   = new Date(bd.value);
            const today = new Date();
            let a = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) a--;
            age.value = a;
        });
    }

    // ──────────────────────────────────────────────
    // GRADE-LEVEL / STRAND SECTION VISIBILITY
    // ──────────────────────────────────────────────
    setupTrackVisibility() {
        const sel = document.querySelectorAll('select')[0];
        if (sel) this.handleGradeLevelChange({ target: sel });
    }

    handleGradeLevelChange(e) {
        const val = e.target.value;
        const sec = document.getElementById('strandSection');
        if (!sec) return;

        const isSHS = (val === 'Grade 11' || val === 'Grade 12');
        sec.classList.toggle('hidden', !isSHS);

        if (!isSHS) {
            document.querySelectorAll('input[name="track"]').forEach(r => r.checked = false);
        }
    }

    // ──────────────────────────────────────────────
    // COLLECT FORM DATA
    // Maps HTML inputs → API payload matching revised schema
    // ──────────────────────────────────────────────
    collectFormData() {
        const gradeOptions = {
            'Grade 7':  1, 'Grade 8': 2, 'Grade 9':  3,
            'Grade 10': 4, 'Grade 11': 5, 'Grade 12': 6
        };

        const gradeSelect  = document.querySelectorAll('select')[0];
        const gradeLevelID = gradeOptions[gradeSelect?.value] || null;

        const sexSelect = document.querySelectorAll('select')[1];
        const gender    = sexSelect?.value || '';

        const trackRadio = document.querySelector('input[name="track"]:checked');
        const strandID   = trackRadio ? parseInt(trackRadio.value) : null;

        const learnerTypeRadio = document.querySelector('input[name="learner-type"]:checked');
        const enrollmentType   = learnerTypeRadio?.value || null;

        const ipRadio  = document.querySelector('input[name="isIPCommunity"]:checked');
        const pwdRadio = document.querySelector('input[name="isPWD"]:checked');
        const fpRadio  = document.querySelector('input[name="is4PsBeneficiary"]:checked');

        return {
            // ── Enrollment ──────────────────────────────────────────
            academicYearID:  this.activeAcademicYear?.AcademicYearID || null,
            gradeLevelID,
            strandID,
            enrollmentType,

            // ── Student ─────────────────────────────────────────────
            lrn:             this.getVal('lrn')            || null,
            lastName:        this.getVal('lastName')        || '',
            firstName:       this.getVal('firstName')       || '',
            middleName:      this.getVal('middleName')      || null,
            extensionName:   this.getVal('extensionName')   || null,
            dateOfBirth:     this.getVal('birthdate')       || '',
            gender,
            religion:        this.getVal('religion')        || null,
            motherTongue:    this.getVal('motherTongue')    || 'Tagalog',
            isIPCommunity:   ipRadio?.value  === '1',
            ipCommunitySpecify: this.getVal('ipCommunitySpecify') || null,
            isPWD:           pwdRadio?.value === '1',
            pwdSpecify:      this.getVal('pwdSpecify')      || null,
            is4PsBeneficiary: fpRadio?.value === '1',

            // ── Address ─────────────────────────────────────────────
            houseNumber:     this.getVal('houseNumber')     || null,
            sitioStreet:     this.getVal('sitioStreet')     || null,
            barangay:        this.getVal('barangay')        || '',
            municipality:    this.getVal('municipality')    || '',
            province:        this.getVal('province')        || '',
            contactNumber:   this.getVal('contactNumber')   || '',

            // ── Parent / Guardian ────────────────────────────────────
            fatherLastName:   this.getVal('fatherLastName')   || null,
            fatherFirstName:  this.getVal('fatherFirstName')  || null,
            fatherMiddleName: this.getVal('fatherMiddleName') || null,
            motherLastName:   this.getVal('motherLastName')   || null,
            motherFirstName:  this.getVal('motherFirstName')  || null,
            motherMiddleName: this.getVal('motherMiddleName') || null,
            guardianLastName:   this.getVal('guardianLastName')   || null,
            guardianFirstName:  this.getVal('guardianFirstName')  || null,
            guardianMiddleName: this.getVal('guardianMiddleName') || null,

            // ── Meta ─────────────────────────────────────────────────
            createdBy: this.currentUser.UserID,
        };
    }

    // ──────────────────────────────────────────────
    // VALIDATION
    // ──────────────────────────────────────────────
    validateForm(d) {
        const errors = [];

        if (!d.academicYearID)  errors.push('No active academic year found. Contact your administrator.');
        if (!d.gradeLevelID)    errors.push('Grade Level is required.');
        if (!d.enrollmentType)  errors.push('Type of Learner must be selected.');
        if (!d.lastName)        errors.push('Last Name is required.');
        if (!d.firstName)       errors.push('Given Name is required.');
        if (!d.dateOfBirth)     errors.push('Birthdate is required.');
        if (!d.gender)          errors.push('Sex is required.');
        if (!d.barangay)        errors.push('Barangay is required.');
        if (!d.municipality)    errors.push('Municipality/City is required.');
        if (!d.province)        errors.push('Province is required.');
        if (!d.contactNumber)   errors.push('Contact Number is required.');

        // LRN format check at submission time (catches paste-in cases that
        // bypassed the real-time input handler)
        if (d.lrn) {
            const lrnCheck = this.validateLRNFormat(d.lrn);
            if (!lrnCheck.valid) {
                errors.push(`LRN format error: ${lrnCheck.message}`);
            }
        }

        // SHS strand required
        if ((d.gradeLevelID === 5 || d.gradeLevelID === 6) && !d.strandID) {
            errors.push('Track/Strand selection is required for Grade 11 & 12.');
        }

        // Strand consistency (front-end guard — also enforced server-side)
        if (this.latestEnrollment && d.gradeLevelID === 6 && this.latestEnrollment.GradeLevelNumber === 11) {
            if (this.latestEnrollment.StrandID && d.strandID &&
                this.latestEnrollment.StrandID !== d.strandID) {
                errors.push(
                    `Strand mismatch: student was enrolled in ${this.latestEnrollment.StrandName} for Grade 11. ` +
                    `Cannot change strand for Grade 12.`
                );
            }
        }

        // Duplicate enrollment guard (front-end)
        if (d.lrn && this.activeAcademicYear) {
            const dup = this.studentEnrollmentHistory.find(
                e => e.AcademicYearID === this.activeAcademicYear.AcademicYearID
            );
            if (dup) {
                errors.push(
                    `Duplicate enrollment: LRN ${d.lrn} is already enrolled for ` +
                    `${this.activeAcademicYear.YearLabel} (${dup.GradeLevelName}` +
                    `${dup.StrandName ? ' – ' + dup.StrandName : ''}). Status: ${dup.Status}.`
                );
            }
        }

        // At least one parent/guardian name
        if (!d.fatherFirstName && !d.motherFirstName && !d.guardianFirstName) {
            errors.push('At least one parent or guardian name is required.');
        }

        return errors;
    }

    // ──────────────────────────────────────────────
    // SUBMIT
    // ──────────────────────────────────────────────
    async handleSubmit(e) {
        e.preventDefault();

        const formData = this.collectFormData();
        const errors   = this.validateForm(formData);

        if (errors.length) {
            alert('Please fix the following errors:\n\n' + errors.join('\n'));
            return;
        }

        // Grade label for confirm dialog
        const gradeLabel = ['','Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'][formData.gradeLevelID] || '';
        const confirmMsg =
            `Confirm enrollment submission:\n\n` +
            `Name:       ${formData.firstName} ${formData.lastName}\n` +
            `Grade:      ${gradeLabel}\n` +
            `School Year: ${this.activeAcademicYear?.YearLabel || '—'}\n\n` +
            `Submit this enrollment form?`;

        if (!confirm(confirmMsg)) return;

        this.showLoading(true);
        try {
            const res    = await fetch('../backend/api/enrollment.php?action=submit', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(formData),
            });
            const result = await res.json();

            if (!result.success) throw new Error(result.message || 'Submission failed');

            alert(`✓ Enrollment submitted successfully!\n\nStatus: Pending Approval`);
            this.handleReset(null, true); // silent reset after success

        } catch (err) {
            console.error(err);
            alert('❌ Error submitting enrollment:\n\n' + err.message);
        } finally {
            this.showLoading(false);
        }
    }

    // ──────────────────────────────────────────────
    // RESET
    // ──────────────────────────────────────────────
    handleReset(e, silent = false) {
        if (e) e.preventDefault();
        if (!silent && !confirm('Reset the form? All entered data will be lost.')) return;

        document.querySelectorAll('input[type="text"], input[type="number"], input[type="date"], input[type="tel"]')
            .forEach(i => i.value = '');
        document.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        document.querySelectorAll('input[type="radio"]').forEach(r => r.checked = false);

        // Restore defaults
        this.setRadio('isIPCommunity',    '0');
        this.setRadio('isPWD',            '0');
        this.setRadio('is4PsBeneficiary', '0');

        this.clearLRNResults();
        this.clearLRNFormatBadge();
        this.clearLRNFormatNotification();
        document.getElementById('lrnNotification')?.remove();

        // Restore read-only school year
        if (this.activeAcademicYear) {
            const sy = document.getElementById('schoolYear');
            if (sy) sy.value = this.activeAcademicYear.YearLabel;
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ──────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────
    getVal(id) {
        const el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    setVal(id, val) {
        const el = document.getElementById(id);
        if (el && val !== null && val !== undefined) el.value = val;
    }

    setRadio(name, value) {
        const radio = document.querySelector(`input[name="${name}"][value="${value}"]`);
        if (radio) radio.checked = true;
    }

    showLoading(show) {
        if (!this.submitBtn) return;
        this.submitBtn.disabled = show;
        const span = this.submitBtn.querySelector('span');
        if (span) span.textContent = show ? 'Submitting…' : 'Submit Enrollment';
    }
}

// ──────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new EnrollmentFormHandler());
} else {
    new EnrollmentFormHandler();
}