// =====================================================
// Bulk Import Handler — djihs_enrollment_v2 schema
// File: js/bulk-import-handler.js
//
// KEY CHANGES vs old version:
//  - selectedSchoolYear (string) → selectedAcademicYear { AcademicYearID, YearLabel }
//  - bulkSchoolYear text input  → bulkAcademicYearSelect dropdown (populated from API)
//  - normalizeRow: removed Age, Weight, Height, ZipCode, Country (not in student table)
//  - normalizeRow: father/mother columns added (all 3 relationships)
//  - parseLearnerType: maps to exact EnrollmentType enum values in schema
//  - validateData: removed weight/height/zip warnings, added Father/Mother/Guardian check
//  - importStudents: sends academicYearID (int FK), not schoolYear string
// =====================================================

class BulkImportHandler {
    constructor() {
        this.uploadedFile         = null;
        this.parsedData           = [];
        this.validRecords         = [];
        this.errors               = [];
        this.warnings             = [];
        this.currentUser          = null;
        this.selectedAcademicYear = null; // { AcademicYearID, YearLabel }
        this.init();
    }

    // ──────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────
    init() {
        this.currentUser = this.getCurrentUser();
        if (!this.currentUser) {
            alert('You must be logged in to import students.');
            return;
        }
        this.checkSheetJSAvailability();
        this.bindEventListeners();
    }

    getCurrentUser() {
        try { return JSON.parse(localStorage.getItem('user') || 'null'); }
        catch { return null; }
    }

    checkSheetJSAvailability() {
        if (typeof XLSX === 'undefined') {
            console.warn('SheetJS not loaded. Excel support unavailable.');
        } else {
            console.log('SheetJS loaded. Version:', XLSX.version);
        }
    }

    // ──────────────────────────────────────────────
    // LOAD ACADEMIC YEAR DROPDOWN
    // Called once when modal opens (lazy load)
    // Populates #bulkAcademicYearSelect
    // ──────────────────────────────────────────────
    async loadAcademicYears() {
        const select = document.getElementById('bulkAcademicYearSelect');
        if (!select) return;

        try {
            const res  = await fetch('../backend/api/academic-year.php?action=available');
            const data = await res.json();
            if (!data.success || !data.academicYears.length) {
                select.innerHTML = '<option value="">No school years available</option>';
                return;
            }

            select.innerHTML = '<option value="">— Select School Year —</option>';
            data.academicYears.forEach(ay => {
                const opt = document.createElement('option');
                opt.value         = ay.AcademicYearID;
                opt.textContent   = ay.YearLabel + (ay.IsActive == 1 ? ' (Active)' : '');
                opt.dataset.label = ay.YearLabel;
                if (ay.IsActive == 1 && !this.selectedAcademicYear) {
                    opt.selected = true;
                    this.selectedAcademicYear = {
                        AcademicYearID: parseInt(ay.AcademicYearID),
                        YearLabel:      ay.YearLabel,
                    };
                }
                select.appendChild(opt);
            });

            select.addEventListener('change', e => {
                const opt = e.target.selectedOptions[0];
                this.selectedAcademicYear = (opt && opt.value)
                    ? { AcademicYearID: parseInt(opt.value), YearLabel: opt.dataset.label }
                    : null;
            });

        } catch (err) {
            console.error('Failed to load academic years:', err);
            select.innerHTML = '<option value="">Error loading years</option>';
        }
    }

    // ──────────────────────────────────────────────
    // EVENT LISTENERS
    // ──────────────────────────────────────────────
    bindEventListeners() {
        document.getElementById('bulkImportBtn')
            ?.addEventListener('click', () => this.openModal());

        document.getElementById('closeModal')
            ?.addEventListener('click', () => this.closeModal());
        document.getElementById('cancelBtn')
            ?.addEventListener('click', () => this.closeModal());

        document.getElementById('fileInput')
            ?.addEventListener('change', e => this.handleFileSelect(e));
        document.getElementById('removeFile')
            ?.addEventListener('click', () => this.removeFile());

        document.getElementById('processBtn')
            ?.addEventListener('click', () => this.processFile());
        document.getElementById('importBtn')
            ?.addEventListener('click', () => this.importStudents());

        document.getElementById('downloadCsvTemplate')
            ?.addEventListener('click', () => this.downloadCsvTemplate());
        document.getElementById('downloadExcelTemplate')
            ?.addEventListener('click', () => this.downloadExcelTemplate());

        this.setupDragAndDrop();
    }

    setupDragAndDrop() {
        const dropZone = document.querySelector('.border-dashed');
        if (!dropZone) return;
        dropZone.addEventListener('dragover', e => {
            e.preventDefault();
            dropZone.classList.add('border-primary', 'bg-primary/5');
        });
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-primary', 'bg-primary/5');
        });
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            dropZone.classList.remove('border-primary', 'bg-primary/5');
            const fi = document.getElementById('fileInput');
            fi.files = e.dataTransfer.files;
            this.handleFileSelect({ target: fi });
        });
    }

    // ──────────────────────────────────────────────
    // MODAL OPEN / CLOSE
    // ──────────────────────────────────────────────
    async openModal() {
        document.getElementById('importModal').classList.remove('hidden');
        await this.loadAcademicYears(); // lazy-load dropdown each open
    }

    closeModal() {
        document.getElementById('importModal').classList.add('hidden');
        this.resetState();
    }

    resetState() {
        document.getElementById('uploadSection').classList.remove('hidden');
        document.getElementById('processingSection').classList.add('hidden');
        document.getElementById('previewSection').classList.add('hidden');
        document.getElementById('importBtn').classList.add('hidden');
        document.getElementById('processBtn').classList.add('hidden');

        const fi = document.getElementById('fileInput');
        if (fi) fi.value = '';
        document.getElementById('fileInfo')?.classList.add('hidden');

        const il = document.getElementById('issuesList');
        if (il) { il.classList.add('hidden'); il.innerHTML = ''; }

        const tb = document.getElementById('previewTableBody');
        if (tb) tb.innerHTML = '';

        this.uploadedFile  = null;
        this.parsedData    = [];
        this.validRecords  = [];
        this.errors        = [];
        this.warnings      = [];
    }

    // ──────────────────────────────────────────────
    // FILE HANDLING
    // ──────────────────────────────────────────────
    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        if (!this.selectedAcademicYear) {
            alert('Please select a School Year first.');
            event.target.value = '';
            return;
        }

        const ext = '.' + file.name.split('.').pop().toLowerCase();
        if (!['.csv', '.xlsx', '.xls'].includes(ext)) {
            alert('Invalid file type. Please upload CSV or Excel (.csv, .xlsx, .xls).');
            event.target.value = '';
            return;
        }
        if (['.xlsx', '.xls'].includes(ext) && typeof XLSX === 'undefined') {
            alert('Excel support requires SheetJS. Please use CSV format.');
            event.target.value = '';
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            alert('File exceeds 10MB limit.');
            event.target.value = '';
            return;
        }
        if (file.size === 0) {
            alert('File is empty.');
            event.target.value = '';
            return;
        }

        this.uploadedFile = file;
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = this.formatFileSize(file.size);
        document.getElementById('fileInfo').classList.remove('hidden');
        document.getElementById('processBtn').classList.remove('hidden');
    }

    removeFile() {
        this.uploadedFile = null;
        document.getElementById('fileInput').value = '';
        document.getElementById('fileInfo').classList.add('hidden');
        document.getElementById('processBtn').classList.add('hidden');
    }

    formatFileSize(bytes) {
        if (bytes < 1024)           return bytes + ' B';
        if (bytes < 1024 * 1024)    return (bytes / 1024).toFixed(2) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    // ──────────────────────────────────────────────
    // FILE PROCESSING
    // ──────────────────────────────────────────────
    async processFile() {
        if (!this.uploadedFile) return;
        if (!this.selectedAcademicYear) {
            alert('Please select a School Year.');
            return;
        }

        document.getElementById('uploadSection').classList.add('hidden');
        document.getElementById('processingSection').classList.remove('hidden');

        try {
            /\.(xlsx|xls)$/i.test(this.uploadedFile.name)
                ? await this.processExcelFile()
                : await this.processCsvFile();
        } catch (err) {
            console.error('File processing error:', err);
            alert('Error processing file: ' + err.message);
            this.closeModal();
        }
    }

    async processCsvFile() {
        return new Promise(resolve => {
            Papa.parse(this.uploadedFile, {
                header:         true,
                skipEmptyLines: true,
                complete: results => {
                    if (!results.data.length) {
                        alert('CSV file contains no data rows.');
                        this.closeModal(); resolve(); return;
                    }
                    this.parsedData = results.data;
                    this.validateData();
                    this.showPreview();
                    resolve();
                },
                error: err => {
                    alert('Error parsing CSV: ' + err.message);
                    this.closeModal(); resolve();
                },
            });
        });
    }

    async processExcelFile() {
        return new Promise(resolve => {
            const reader = new FileReader();
            reader.onload = e => {
                try {
                    const wb   = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellText: false, cellDates: true });
                    const ws   = wb.Sheets[wb.SheetNames[0]];
                    const json = XLSX.utils.sheet_to_json(ws, { raw: false, defval: '', blankrows: false });

                    if (!json.length) {
                        alert('Excel file is empty.'); this.closeModal(); resolve(); return;
                    }
                    const chk = this.validateExcelStructure(json[0]);
                    if (!chk.valid) {
                        alert('Excel structure invalid:\n\n' + chk.errors.join('\n'));
                        this.closeModal(); resolve(); return;
                    }
                    this.parsedData = json;
                    this.validateData();
                    this.showPreview();
                    resolve();
                } catch (err) {
                    alert('Error processing Excel: ' + err.message);
                    this.closeModal(); resolve();
                }
            };
            reader.onerror = () => { alert('Error reading file.'); this.closeModal(); resolve(); };
            reader.readAsArrayBuffer(this.uploadedFile);
        });
    }

    validateExcelStructure(firstRow) {
        const required = ['GRADE LEVEL TO ENROLL', 'LAST NAME', 'FIRST NAME', 'LRN', 'BIRTH DATE', 'SEX', 'CONTACT NO.'];
        const missing  = required.filter(c => !(c in firstRow));
        return missing.length
            ? { valid: false, errors: ['Missing required columns:', ...missing.map(c => `  - ${c}`)] }
            : { valid: true, errors: [] };
    }

    // ──────────────────────────────────────────────
    // VALIDATION
    // Removed: Age, Weight, Height, ZipCode, Country (not in student table)
    // ──────────────────────────────────────────────
    validateData() {
        this.validRecords = [];
        this.errors       = [];
        this.warnings     = [];

        // Detect within-file duplicate LRNs
        const lrnMap = new Map();
        this.parsedData.forEach((row, i) => {
            const lrn = (row['LRN'] || '').toString().trim();
            if (lrn) {
                if (!lrnMap.has(lrn)) lrnMap.set(lrn, []);
                lrnMap.get(lrn).push(i + 2);
            }
        });
        lrnMap.forEach((rows, lrn) => {
            if (rows.length > 1)
                this.errors.push(`Duplicate LRN "${lrn}" found in rows: ${rows.join(', ')}`);
        });

        this.parsedData.forEach((row, index) => {
            const rowNum = index + 2;
            const issues = [];
            const norm   = this.normalizeRow(row);

            // ── LRN ─────────────────────────────────────────────────────
            const rawLRN = (row['LRN'] || '').toString().trim();
            if (!rawLRN) {
                issues.push(`Row ${rowNum}: LRN is required`);
            } else {
                let clean = rawLRN;
                if (/[Ee]\+/.test(clean)) clean = parseFloat(clean).toFixed(0);
                clean = clean.replace(/\D/g, '');
                if (clean.length !== 12)
                    issues.push(`Row ${rowNum}: LRN must be 12 digits (found ${clean.length}: "${clean}")`);
            }

            // ── Names ─────────────────────────────────────────────────────
            if (!norm.lastName)  issues.push(`Row ${rowNum}: Last Name is required`);
            if (!norm.firstName) issues.push(`Row ${rowNum}: First Name is required`);
            if (norm.lastName?.length  > 100) issues.push(`Row ${rowNum}: Last Name too long`);
            if (norm.firstName?.length > 100) issues.push(`Row ${rowNum}: First Name too long`);

            // ── Grade Level ───────────────────────────────────────────────
            if (!norm.gradeLevelID)
                issues.push(`Row ${rowNum}: Invalid Grade Level (must be 7–12, found: "${row['GRADE LEVEL TO ENROLL']}")`);

            // ── Birth Date ────────────────────────────────────────────────
            const rawBD = (row['BIRTH DATE'] || '').toString().trim();
            if (!rawBD) {
                issues.push(`Row ${rowNum}: Birth Date is required`);
            } else if (!norm.dateOfBirth) {
                issues.push(`Row ${rowNum}: Invalid Birth Date. Expected MM/DD/YYYY. Got: "${rawBD}"`);
            } else {
                const birthYear = new Date(norm.dateOfBirth).getFullYear();
                const curYear   = new Date().getFullYear();
                if (birthYear < 1990 || birthYear > curYear - 10)
                    this.warnings.push(`Row ${rowNum}: Birth year ${birthYear} seems unusual for a high school student`);
            }

            // ── Sex ───────────────────────────────────────────────────────
            const rawSex = (row['SEX'] || '').toString().trim();
            if (!rawSex) {
                issues.push(`Row ${rowNum}: Sex is required`);
            } else if (!['M','F','MALE','FEMALE','Male','Female'].includes(rawSex)) {
                issues.push(`Row ${rowNum}: Sex must be Male/Female or M/F. Got: "${rawSex}"`);
            }

            // ── Contact Number ────────────────────────────────────────────
            const rawContact = (row['CONTACT NO.'] || '').toString().trim();
            if (!rawContact) {
                issues.push(`Row ${rowNum}: Contact Number is required`);
            } else {
                let clean = rawContact;
                if (/[Ee]\+/.test(clean)) clean = parseFloat(clean).toFixed(0);
                clean = clean.replace(/\D/g, '');
                if (clean.length < 10)
                    issues.push(`Row ${rowNum}: Contact Number too short (min 10 digits, found ${clean.length})`);
                else if (clean.length > 15)
                    issues.push(`Row ${rowNum}: Contact Number too long (max 15 digits, found ${clean.length})`);
                else if (clean.length === 11 && !clean.startsWith('09'))
                    this.warnings.push(`Row ${rowNum}: Philippine mobile numbers typically start with 09 (found: "${clean}")`);
            }

            // ── SHS Strand ────────────────────────────────────────────────
            if ((norm.gradeLevelID === 5 || norm.gradeLevelID === 6) && !norm.strandID)
                issues.push(`Row ${rowNum}: Strand is required for Grade 11 & 12`);
            if (norm.gradeLevelID >= 1 && norm.gradeLevelID <= 4 && norm._strandLabel)
                this.warnings.push(`Row ${rowNum}: Strand will be ignored for Junior High (Grades 7–10)`);

            // ── Parent/Guardian ───────────────────────────────────────────
            const hasFather   = !!(norm.fatherFirstName   || norm.fatherLastName);
            const hasMother   = !!(norm.motherFirstName   || norm.motherLastName);
            const hasGuardian = !!(norm.guardianFirstName || norm.guardianLastName);
            if (!hasFather && !hasMother && !hasGuardian)
                this.warnings.push(`Row ${rowNum}: At least one parent or guardian name is recommended`);

            // ── Address ───────────────────────────────────────────────────
            if (!norm.barangay)     this.warnings.push(`Row ${rowNum}: Barangay is recommended`);
            if (!norm.municipality) this.warnings.push(`Row ${rowNum}: Municipality/City is recommended`);

            if (issues.length > 0) {
                this.errors.push(...issues);
            } else {
                this.validRecords.push({ row: rowNum, data: norm });
            }
        });
    }

    // ──────────────────────────────────────────────
    // NORMALIZE ROW → schema-exact payload
    //
    // REMOVED columns (not in student table):
    //   Age, Weight, Height, ZipCode, Country,
    //   IsTransferee, EncodedDate, EncodedBy, CreatedBy, UpdatedAt
    //
    // ADDED (parentguardian table):
    //   fatherLastName/FirstName/MiddleName
    //   motherLastName/FirstName/MiddleName
    //
    // enrollmentType maps to exact ENUM:
    //   Regular_Old_Student | Regular_New_Student | Late |
    //   Transferee | Balik_Aral | Repeater | ALS
    // ──────────────────────────────────────────────
    normalizeRow(row) {
        // LRN — handle scientific notation
        let lrn = (row['LRN'] || '').toString().trim();
        if (/[Ee]\+/.test(lrn)) lrn = parseFloat(lrn).toFixed(0);
        lrn = lrn.replace(/\D/g, '');

        // Contact number — handle scientific notation
        let contact = (row['CONTACT NO.'] || '').toString().trim();
        if (/[Ee]\+/.test(contact)) contact = parseFloat(contact).toFixed(0);
        contact = contact.replace(/\D/g, '');

        const gradeLevelID = this.parseGradeLevel(row['GRADE LEVEL TO ENROLL']);
        const isSHS        = gradeLevelID === 5 || gradeLevelID === 6;
        const strandID     = isSHS ? this.parseStrandID(row['STRAND'] || '') : null;

        return {
            // Enrollment
            academicYearID: this.selectedAcademicYear.AcademicYearID,
            gradeLevelID,
            strandID,
            enrollmentType: this.parseEnrollmentType(row['TYPE OF LEARNER']),

            // Student — exact schema columns only
            lrn,
            lastName:       (row['LAST NAME']    || '').trim().toUpperCase(),
            firstName:      (row['FIRST NAME']   || '').trim().toUpperCase(),
            middleName:     (row['MIDDLE NAME']  || '').trim().toUpperCase() || null,
            extensionName:  null,
            dateOfBirth:    this.parseDateString(row['BIRTH DATE']),
            gender:         this.normalizeGender(row['SEX']),
            religion:       (row['RELIGION']     || '').trim() || null,
            motherTongue:   (row['MOTHER TONGUE']|| 'Tagalog').trim(),
            isIPCommunity:  this.parseYesNo(row['BELONGING TO ANY INDIGENOUS PEOPLES (IP)']),
            ipCommunitySpecify: null,
            isPWD:          false,
            pwdSpecify:     null,
            is4PsBeneficiary: this.parseYesNo(row["IS YOUR FAMILY A BENEFICIARY OF 4P'S?"]),

            // Address
            houseNumber:    (row['HOUSE NO.']           || '').trim() || null,
            sitioStreet:    (row['STREET NAME']         || '').trim() || null,
            barangay:       (row['BARANGAY']            || '').trim(),
            municipality:   (row['MUNICIPALITY / CITY'] || '').trim(),
            province:       (row['PROVINCE']            || '').trim(),
            contactNumber:  contact,

            // Parent/Guardian → parentguardian table (3 rows per student)
            fatherLastName:     (row['FATHER LAST NAME']    || '').trim().toUpperCase() || null,
            fatherFirstName:    (row['FATHER FIRST NAME']   || '').trim().toUpperCase() || null,
            fatherMiddleName:   (row['FATHER MIDDLE NAME']  || '').trim().toUpperCase() || null,
            motherLastName:     (row['MOTHER LAST NAME']    || '').trim().toUpperCase() || null,
            motherFirstName:    (row['MOTHER FIRST NAME']   || '').trim().toUpperCase() || null,
            motherMiddleName:   (row['MOTHER MIDDLE NAME']  || '').trim().toUpperCase() || null,
            guardianLastName:   (row['GUARDIAN LAST NAME']  || '').trim().toUpperCase() || null,
            guardianFirstName:  (row['GUARDIAN FIRST NAME'] || '').trim().toUpperCase() || null,
            guardianMiddleName: (row['GUARDIAN MIDDLE NAME']|| '').trim().toUpperCase() || null,

            // For display only (not sent to server)
            _strandLabel: (row['STRAND'] || '').trim(),
        };
    }

    // ──────────────────────────────────────────────
    // PARSE HELPERS
    // ──────────────────────────────────────────────
    parseDateString(dateStr) {
        if (!dateStr) return null;
        if (dateStr instanceof Date && !isNaN(dateStr)) return this.toMySQLDate(dateStr);

        const str = dateStr.toString().trim();

        // Excel serial number
        if (/^\d{4,6}$/.test(str)) {
            return this.toMySQLDate(new Date(new Date(1899, 11, 30).getTime() + Number(str) * 86400000));
        }

        // MM/DD/YYYY (primary)
        const slash = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (slash) {
            const [, mm, dd, yyyy] = slash;
            const d = new Date(`${yyyy}-${mm.padStart(2,'0')}-${dd.padStart(2,'0')}`);
            if (!isNaN(d) && d.getFullYear() === +yyyy && d.getMonth() === +mm - 1 && d.getDate() === +dd)
                return this.toMySQLDate(d);
        }

        // YYYY-MM-DD (fallback)
        if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
            const d = new Date(str);
            if (!isNaN(d)) return str;
        }

        return null;
    }

    toMySQLDate(d) {
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    }

    normalizeGender(sex) {
        if (!sex) return 'Male';
        const n = sex.toString().trim().toUpperCase();
        return (n === 'F' || n === 'FEMALE') ? 'Female' : 'Male';
    }

    parseGradeLevel(str) {
        if (!str) return null;
        const m = str.toString().match(/(\d+)/);
        if (m) {
            const n = parseInt(m[1]);
            if (n >= 7 && n <= 12) return n - 6; // 7→1 … 12→6
            if (n >= 1 && n <= 6)  return n;
        }
        return null;
    }

    // Maps CSV "TYPE OF LEARNER" → exact EnrollmentType ENUM values in schema
    // Valid: Regular_Old_Student | Regular_New_Student | Late |
    //        Transferee | Balik_Aral | Repeater | ALS
    parseEnrollmentType(str) {
        if (!str) return 'Regular_New_Student';
        const n = str.toString().toUpperCase();
        if (n.includes('OLD'))                               return 'Regular_Old_Student';
        if (n.includes('NEW'))                               return 'Regular_New_Student';
        if (n.includes('ALS'))                               return 'ALS';
        if (n.includes('BALIK'))                             return 'Balik_Aral';
        if (n.includes('REPEATER'))                          return 'Repeater';
        if (n.includes('TRANSFEREE') || n.includes('TRANS')) return 'Transferee';
        if (n.includes('LATE'))                              return 'Late';
        return 'Regular_New_Student';
    }

    parseStrandID(str) {
        if (!str) return null;
        const n = str.toString().toUpperCase();
        if (n.includes('ABM'))                                    return 1;
        if (n.includes('HUMSS') || n.includes('HUMANITIES'))      return 2;
        if (n.includes('STEM'))                                    return 3;
        if (n.includes('COOKERY') || n.includes('HE'))            return 4;
        if (n.includes('ICT') || n.includes('CSS'))               return 5;
        if (n.includes('IA') || n.includes('EIM') || n.includes('ELECTRICAL')) return 6;
        return null;
    }

    parseYesNo(val) {
        if (!val) return false;
        return ['YES','Y','1','TRUE'].includes(val.toString().toUpperCase().trim());
    }

    // ──────────────────────────────────────────────
    // PREVIEW
    // ──────────────────────────────────────────────
    showPreview() {
        document.getElementById('processingSection').classList.add('hidden');
        document.getElementById('previewSection').classList.remove('hidden');
        document.getElementById('importBtn').classList.remove('hidden');

        const total    = this.parsedData.length;
        const valid    = this.validRecords.length;
        const errCount = this.errors.length;
        const warnCount= this.warnings.length;

        ['totalCount','totalCountCard'].forEach(id => this.setText(id, total));
        ['validCount','validCountCard'].forEach(id => this.setText(id, valid));
        ['errorCount','errorCountCard'].forEach(id => this.setText(id, errCount));
        ['warningCount','warningCountCard'].forEach(id => this.setText(id, warnCount));
        this.setText('importCountLabel', valid);
        this.setText('previewSchoolYear', this.selectedAcademicYear?.YearLabel || '—');

        // Map row# → its errors for cell-level highlighting
        const rowErrorsMap = new Map();
        this.errors.forEach(e => {
            const m = e.match(/Row (\d+):/);
            if (m) {
                const n = parseInt(m[1]);
                if (!rowErrorsMap.has(n)) rowErrorsMap.set(n, []);
                rowErrorsMap.get(n).push(e.replace(/Row \d+:\s*/, ''));
            }
        });

        // Issues list
        const il = document.getElementById('issuesList');
        if (errCount > 0 || warnCount > 0) {
            il.classList.remove('hidden');
            let html = '';
            this.errors.slice(0, 15).forEach(e => {
                html += `<div class="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 rounded-lg p-3 text-sm text-red-800 dark:text-red-200">
                    <span class="material-icons-outlined text-red-600 text-[16px] align-middle mr-2">error</span>
                    ${this.escapeHtml(e)}</div>`;
            });
            if (this.errors.length > 15)
                html += `<div class="text-center text-sm text-gray-500 py-2">… and ${this.errors.length - 15} more errors</div>`;

            this.warnings.slice(0, 10).forEach(w => {
                html += `<div class="bg-yellow-50 dark:bg-yellow-900/10 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 text-sm text-yellow-800 dark:text-yellow-200">
                    <span class="material-icons-outlined text-yellow-600 text-[16px] align-middle mr-2">warning</span>
                    ${this.escapeHtml(w)}</div>`;
            });
            if (this.warnings.length > 10)
                html += `<div class="text-center text-sm text-gray-500 py-2">… and ${this.warnings.length - 10} more warnings</div>`;
            il.innerHTML = html;
        } else {
            il.classList.add('hidden');
        }

        // Build preview rows (valid + invalid, sorted by row number)
        const validSet = new Set(this.validRecords.map(r => r.row));
        const allRows  = [];
        this.validRecords.forEach(r =>
            allRows.push({ rowNum: r.row, data: r.data, isValid: true, errors: [] }));
        this.parsedData.forEach((row, i) => {
            const n = i + 2;
            if (!validSet.has(n))
                allRows.push({ rowNum: n, data: this.normalizeRow(row), isValid: false, errors: rowErrorsMap.get(n) || ['Validation failed'] });
        });
        allRows.sort((a, b) => a.rowNum - b.rowNum);

        const gradeNames = ['','Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'];
        const tbody = document.getElementById('previewTableBody');

        tbody.innerHTML = allRows.slice(0, 50).map(item => {
            const d   = item.data;
            const cls = item.isValid
                ? 'hover:bg-gray-50/50 dark:hover:bg-gray-800/30'
                : 'bg-red-50/30 dark:bg-red-900/10';

            // Guardian display: prefer Guardian row, fall back to Father
            const guardianName = (d.guardianFirstName && d.guardianLastName)
                ? `${d.guardianLastName}, ${d.guardianFirstName}`
                : (d.fatherFirstName ? `${d.fatherLastName || ''}, ${d.fatherFirstName}` : '-');

            return `<tr class="${cls}">
                <td class="px-4 py-3 text-sm text-gray-400">${item.rowNum}</td>
                ${this.buildCell(d.lrn,                      item.errors.some(e=>e.includes('LRN')),     item.errors.find(e=>e.includes('LRN')))}
                ${this.buildCell(d.lastName && d.firstName ? `${d.lastName}, ${d.firstName}` : '',
                                                             item.errors.some(e=>e.includes('Name')),    item.errors.find(e=>e.includes('Name')))}
                ${this.buildCell(gradeNames[d.gradeLevelID]||'N/A',
                                                             item.errors.some(e=>e.includes('Grade')),   item.errors.find(e=>e.includes('Grade')))}
                ${this.buildCell(d._strandLabel||'-',        item.errors.some(e=>e.includes('strand')),  item.errors.find(e=>e.includes('strand')))}
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${this.escapeHtml(guardianName)}</td>
                ${this.buildCell(d.contactNumber,            item.errors.some(e=>e.includes('Contact')), item.errors.find(e=>e.includes('Contact')))}
                <td class="px-4 py-3">
                    ${item.isValid
                        ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Valid</span>'
                        : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">Error</span>'}
                </td>
            </tr>`;
        }).join('');

        if (allRows.length > 50)
            tbody.innerHTML += `<tr><td colspan="8" class="px-4 py-3 text-center text-sm text-gray-500">… and ${allRows.length - 50} more records</td></tr>`;

        // Summary bar
        const errSummary  = document.getElementById('errorSummary');
        const validSummary = document.getElementById('validSummary');
        const fixBtn       = document.getElementById('fixErrorsBtn');

        errSummary?.classList.toggle('hidden', errCount === 0);
        fixBtn?.classList.toggle('hidden',     errCount === 0);
        validSummary?.classList.toggle('hidden', valid === 0);

        if (fixBtn) {
            fixBtn.onclick = () => {
                this.resetState();
                document.getElementById('uploadSection').classList.remove('hidden');
            };
        }
    }

    buildCell(content, hasError, errMsg) {
        if (!hasError)
            return `<td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${this.escapeHtml(content || '')}</td>`;

        if (!content || content === 'N/A' || content === '-')
            return `<td class="px-4 py-3 relative group">
                <div class="flex items-center justify-center h-8 bg-red-100/50 dark:bg-red-900/20 rounded border border-dashed border-red-300 dark:border-red-700">
                    <span class="material-icons-outlined text-sm text-red-500">error_outline</span>
                </div>
                <div class="invisible group-hover:visible absolute -top-8 left-1/2 -translate-x-1/2 z-50 px-3 py-1.5 bg-gray-900 text-white text-xs rounded whitespace-nowrap shadow-lg pointer-events-none">
                    ${this.escapeHtml(errMsg || 'Required')}
                    <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900"></div>
                </div>
            </td>`;

        return `<td class="px-4 py-3 relative group">
            <div class="flex items-center gap-1.5 text-red-600 dark:text-red-400 bg-red-100/50 dark:bg-red-900/20 px-2 py-1 rounded border border-red-200 dark:border-red-800">
                ${this.escapeHtml(content)}<span class="material-icons-outlined text-sm">warning</span>
            </div>
            <div class="invisible group-hover:visible absolute -top-8 left-1/2 -translate-x-1/2 z-50 px-3 py-1.5 bg-gray-900 text-white text-xs rounded whitespace-nowrap shadow-lg pointer-events-none max-w-xs">
                ${this.escapeHtml(errMsg || 'Invalid')}
                <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900"></div>
            </div>
        </td>`;
    }

    escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    setText(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    // ──────────────────────────────────────────────
    // IMPORT — sends academicYearID (int FK), not string
    // ──────────────────────────────────────────────
    async importStudents() {
        if (!this.validRecords.length) { alert('No valid records to import.'); return; }

        const ay  = this.selectedAcademicYear?.YearLabel || '—';
        const msg = `Import ${this.validRecords.length} student(s) for School Year ${ay}?\n\nThis will create pending enrollments.`
            + (this.errors.length ? `\n\nNote: ${this.errors.length} records with errors will NOT be imported. Continue?` : '');
        const confirmed = await confirmAuditAction(
            'Confirm Bulk Import',
            `You are about to import ${this.validRecords.length} student(s) for School Year ${ay}. This will be permanently recorded in the audit trail.`
        );
        if (!confirmed) return;

        document.getElementById('previewSection').classList.add('hidden');
        document.getElementById('processingSection').classList.remove('hidden');

        try {
            const res    = await fetch('../backend/api/enrollment-bulk-import.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    students:  this.validRecords.map(r => r.data),
                    createdBy: this.currentUser.UserID,
                }),
            });
            const result = await res.json();
            if (!result.success) throw new Error(result.message || 'Import failed');

            const { total, success, failed, errors } = result.results;
            let out = `✓ Import Complete!\n\nSchool Year: ${ay}\nProcessed: ${total}\nImported: ${success}\nFailed: ${failed}`;
            if (errors?.length) out += '\n\nFirst errors:\n' + errors.slice(0, 5).join('\n');
            alert(out);

            this.resetState();
            this.closeModal();
            setTimeout(() => window.location.reload(), 500);

        } catch (err) {
            console.error('Import error:', err);
            alert('❌ Error importing:\n\n' + err.message);
            this.resetState();
            this.closeModal();
        }
    }

    // ──────────────────────────────────────────────
    // TEMPLATE DOWNLOADS
    // Updated columns: removed Age/Weight/Height/Zip/Country,
    // added Father/Mother separate columns
    // ──────────────────────────────────────────────
    downloadCsvTemplate() {
        const headers = [
            'GRADE LEVEL TO ENROLL','LAST NAME','FIRST NAME','MIDDLE NAME',
            'TYPE OF LEARNER','STRAND','LRN','BIRTH DATE','SEX',
            'BELONGING TO ANY INDIGENOUS PEOPLES (IP)',
            "IS YOUR FAMILY A BENEFICIARY OF 4P'S?",
            'RELIGION','MOTHER TONGUE',
            'HOUSE NO.','STREET NAME','BARANGAY','MUNICIPALITY / CITY','PROVINCE',
            'CONTACT NO.',
            'FATHER LAST NAME','FATHER FIRST NAME','FATHER MIDDLE NAME',
            'MOTHER LAST NAME','MOTHER FIRST NAME','MOTHER MIDDLE NAME',
            'GUARDIAN LAST NAME','GUARDIAN FIRST NAME','GUARDIAN MIDDLE NAME',
        ];
        const sample = [
            ['GRADE 11','DELA CRUZ','JUAN','SANTOS','REGULAR - NEW STUDENT','ABM',
             '123456789012','1/15/2008','MALE','NO','NO','Roman Catholic','Tagalog',
             'Block 1 Lot 5','Sampaguita Street','Don Jose','Santa Rosa','Laguna','09123456789',
             'DELA CRUZ','PEDRO','REYES','SANTOS','MARIA','GARCIA','','',''],
            ['GRADE 12','SANTOS','MARIA','GARCIA','REGULAR - OLD STUDENT','STEM',
             '123456789013','3/22/2007','FEMALE','NO','YES','Iglesia ni Cristo','Tagalog',
             'House 23','Rizal Avenue','Poblacion','Biñan','Laguna','09234567890',
             '','','','SANTOS','JOSE','MARTINEZ','','',''],
        ];

        const csv = [headers.join(','), ...sample.map(r =>
            r.map(c => (c.includes(',') || c.includes('"')) ? `"${c.replace(/"/g,'""')}"` : c).join(',')
        )].join('\n');

        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const a   = Object.assign(document.createElement('a'), { href: url, download: 'DJIHS_Student_Import_Template.csv' });
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    downloadExcelTemplate() {
        const a = Object.assign(document.createElement('a'), {
            href: '../backend/templates/DJIHS_Student_Import_Template.xlsx',
            download: 'DJIHS_Student_Import_Template.xlsx',
        });
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new BulkImportHandler());
} else {
    new BulkImportHandler();
}