// =====================================================
// Enhanced Bulk Import Handler with School Year Selection and Excel Support
// File: js/bulk-import-handler.js
// =====================================================

class BulkImportHandler {
    constructor() {
        this.uploadedFile = null;
        this.parsedData = [];
        this.validRecords = [];
        this.errors = [];
        this.warnings = [];
        this.currentUser = null;
        this.selectedSchoolYear = '';
        this.init();
    }

    init() {
        this.currentUser = this.getCurrentUser();
        if (!this.currentUser) {
            alert('You must be logged in to import students');
            return;
        }

        this.bindEventListeners();
        this.initializeSchoolYear();
        this.checkSheetJSAvailability();
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

    checkSheetJSAvailability() {
        if (typeof XLSX === 'undefined') {
            console.warn('⚠️ SheetJS library not loaded. Excel support will be limited.');
            console.warn('Please ensure SheetJS is loaded BEFORE bulk-import-handler.js');
        } else {
            console.log('✓ SheetJS library loaded successfully');
            console.log('  Version:', XLSX.version);
            console.log('  Excel template download: Available');
            console.log('  Excel file import: Available');
        }
    }

    initializeSchoolYear() {
        // Set default school year (current academic year)
        const currentYear = new Date().getFullYear();
        const currentMonth = new Date().getMonth();
        
        // If we're past June, use current year - next year
        // Otherwise use previous year - current year
        if (currentMonth >= 5) { // June onwards
            this.selectedSchoolYear = `${currentYear}-${currentYear + 1}`;
        } else {
            this.selectedSchoolYear = `${currentYear - 1}-${currentYear}`;
        }
    }

    bindEventListeners() {
        // Open modal
        const bulkImportBtn = document.getElementById('bulkImportBtn');
        if (bulkImportBtn) {
            bulkImportBtn.addEventListener('click', () => this.openModal());
        }

        // Close modal
        document.getElementById('closeModal').addEventListener('click', () => this.closeModal());
        document.getElementById('cancelBtn').addEventListener('click', () => this.closeModal());

        // File input
        const fileInput = document.getElementById('fileInput');
        fileInput.addEventListener('change', (e) => this.handleFileSelect(e));

        // Remove file
        document.getElementById('removeFile').addEventListener('click', () => this.removeFile());

        // Process button
        document.getElementById('processBtn').addEventListener('click', () => this.processFile());

        // Import button
        document.getElementById('importBtn').addEventListener('click', () => this.importStudents());

        // Template downloads
        document.getElementById('downloadCsvTemplate').addEventListener('click', () => this.downloadCsvTemplate());
        document.getElementById('downloadExcelTemplate').addEventListener('click', () => this.downloadExcelTemplate());

        // School year input
        const schoolYearInput = document.getElementById('bulkSchoolYear');
        if (schoolYearInput) {
            schoolYearInput.addEventListener('change', (e) => {
                this.selectedSchoolYear = e.target.value;
            });
        }

        // Drag and drop
        this.setupDragAndDrop();
    }

    setupDragAndDrop() {
        const dropZone = document.querySelector('.border-dashed');
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-primary', 'bg-primary/5');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-primary', 'bg-primary/5');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-primary', 'bg-primary/5');
            const file = e.dataTransfer.files[0];
            if (file) {
                const fileInput = document.getElementById('fileInput');
                fileInput.files = e.dataTransfer.files;
                this.handleFileSelect({ target: fileInput });
            }
        });
    }

    openModal() {
        document.getElementById('importModal').classList.remove('hidden');
        
        // Set the school year input value
        const schoolYearInput = document.getElementById('bulkSchoolYear');
        if (schoolYearInput) {
            schoolYearInput.value = this.selectedSchoolYear;
        }
    }

    closeModal() {
        document.getElementById('importModal').classList.add('hidden');
        this.resetState();
    }

    resetState() {
        // Reset view
        document.getElementById('uploadSection').classList.remove('hidden');
        document.getElementById('processingSection').classList.add('hidden');
        document.getElementById('previewSection').classList.add('hidden');
        document.getElementById('importBtn').classList.add('hidden');
        
        // Clear file input
        document.getElementById('fileInput').value = '';
        document.getElementById('fileInfo').classList.add('hidden');
        document.getElementById('processBtn').classList.add('hidden');
        
        // Clear issues list
        const issuesList = document.getElementById('issuesList');
        if (issuesList) {
            issuesList.classList.add('hidden');
            issuesList.innerHTML = '';
        }
        
        // Clear preview table
        const tbody = document.getElementById('previewTableBody');
        if (tbody) {
            tbody.innerHTML = '';
        }
        
        // Reset data
        this.uploadedFile = null;
        this.parsedData = [];
        this.validRecords = [];
        this.errors = [];
        this.warnings = [];
    }

    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Validate school year first
        const schoolYearInput = document.getElementById('bulkSchoolYear');
        if (!schoolYearInput || !schoolYearInput.value) {
            alert('Please select a School Year first');
            event.target.value = '';
            return;
        }

        this.selectedSchoolYear = schoolYearInput.value;

        // Validate file type
        const validTypes = ['.csv', '.xlsx', '.xls'];
        const fileExt = '.' + file.name.split('.').pop().toLowerCase();
        
        if (!validTypes.includes(fileExt)) {
            alert('Invalid file type. Please upload a CSV or Excel file (.csv, .xlsx, .xls)');
            event.target.value = '';
            return;
        }

        // Check if SheetJS is available for Excel files
        if ((fileExt === '.xlsx' || fileExt === '.xls') && typeof XLSX === 'undefined') {
            alert('Excel support requires SheetJS library. Please use CSV format or include the SheetJS library.');
            event.target.value = '';
            return;
        }

        // Validate file size (10MB max)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size exceeds 10MB limit. Please reduce the file size.');
            event.target.value = '';
            return;
        }

        // Check for empty file
        if (file.size === 0) {
            alert('File is empty. Please upload a valid file with data.');
            event.target.value = '';
            return;
        }

        this.uploadedFile = file;

        // Show file info
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
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    async processFile() {
        if (!this.uploadedFile) return;

        // Validate school year again
        if (!this.selectedSchoolYear || !/^\d{4}-\d{4}$/.test(this.selectedSchoolYear)) {
            alert('Please enter a valid School Year in YYYY-YYYY format');
            return;
        }

        // Switch to processing view
        document.getElementById('uploadSection').classList.add('hidden');
        document.getElementById('processingSection').classList.remove('hidden');

        const isExcel = this.uploadedFile.name.match(/\.(xlsx|xls)$/i);

        try {
            if (isExcel) {
                await this.processExcelFile();
            } else {
                await this.processCsvFile();
            }
        } catch (error) {
            console.error('File processing error:', error);
            alert('Error processing file: ' + error.message);
            this.closeModal();
        }
    }

    async processCsvFile() {
        return new Promise((resolve) => {
            Papa.parse(this.uploadedFile, {
                header: true,
                skipEmptyLines: true,
                complete: (results) => {
                    if (results.errors && results.errors.length > 0) {
                        console.warn('CSV parsing warnings:', results.errors);
                    }
                    
                    this.parsedData = results.data;
                    
                    if (this.parsedData.length === 0) {
                        alert('CSV file contains no data rows');
                        this.closeModal();
                        resolve();
                        return;
                    }
                    
                    this.validateData();
                    this.showPreview();
                    resolve();
                },
                error: (error) => {
                    alert('Error parsing CSV: ' + error.message);
                    this.closeModal();
                    resolve();
                }
            });
        });
    }

    async processExcelFile() {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            
            reader.onload = (e) => {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { 
                        type: 'array',
                        cellText: false,
                        cellDates: true
                    });
                    
                    // Get the first sheet
                    const firstSheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[firstSheetName];
                    
                    // Convert to JSON with header row
                    const jsonData = XLSX.utils.sheet_to_json(worksheet, {
                        raw: false, // Keep values as strings to preserve LRN
                        defval: '', // Default value for empty cells
                        blankrows: false
                    });
                    
                    if (jsonData.length === 0) {
                        alert('Excel file is empty or has no data rows');
                        this.closeModal();
                        resolve();
                        return;
                    }
                    
                    // Validate Excel structure
                    const validation = this.validateExcelStructure(jsonData[0]);
                    if (!validation.valid) {
                        alert('Excel file structure is invalid:\n\n' + validation.errors.join('\n'));
                        this.closeModal();
                        resolve();
                        return;
                    }
                    
                    this.parsedData = jsonData;
                    this.validateData();
                    this.showPreview();
                    resolve();
                    
                } catch (error) {
                    console.error('Excel processing error:', error);
                    alert('Error processing Excel file: ' + error.message);
                    this.closeModal();
                    resolve();
                }
            };
            
            reader.onerror = (error) => {
                alert('Error reading Excel file');
                this.closeModal();
                resolve();
            };
            
            reader.readAsArrayBuffer(this.uploadedFile);
        });
    }

    validateExcelStructure(firstRow) {
        const requiredColumns = [
            'GRADE LEVEL TO ENROLL',
            'LAST NAME',
            'FIRST NAME',
            'LRN',
            'BIRTH DATE',
            'SEX',
            'CONTACT NO.'
        ];
        
        const missingColumns = [];
        const availableColumns = Object.keys(firstRow);
        
        for (const col of requiredColumns) {
            if (!availableColumns.includes(col)) {
                missingColumns.push(col);
            }
        }
        
        if (missingColumns.length > 0) {
            return {
                valid: false,
                errors: [
                    'Missing required columns:',
                    ...missingColumns.map(col => `  - ${col}`)
                ]
            };
        }
        
        return { valid: true, errors: [] };
    }

    validateData() {
        this.validRecords = [];
        this.errors = [];
        this.warnings = [];

        // Check for duplicate LRNs in the uploaded file
        const lrnMap = new Map();
        this.parsedData.forEach((row, index) => {
            const lrn = (row['LRN'] || '').toString().trim();
            if (lrn) {
                if (lrnMap.has(lrn)) {
                    lrnMap.get(lrn).push(index + 2);
                } else {
                    lrnMap.set(lrn, [index + 2]);
                }
            }
        });

        // Report duplicate LRNs
        lrnMap.forEach((rows, lrn) => {
            if (rows.length > 1) {
                this.errors.push(`Duplicate LRN "${lrn}" found in rows: ${rows.join(', ')}`);
            }
        });

        this.parsedData.forEach((row, index) => {
            const issues = [];
            const rowNum = index + 2; // +2 for header and 0-index

            // Get RAW values before normalization for better error messages
            const rawLRN = (row['LRN'] || '').toString().trim();
            const rawSex = (row['SEX'] || '').toString().trim();
            const rawBirthdate = (row['BIRTH DATE'] || '').toString().trim();
            const rawContactNumber = (row['CONTACT NO.'] || '').toString().trim();
            const rawZipCode = (row['ZIP CODE'] || '').toString().trim();

            // Normalize field names
            const normalizedRow = this.normalizeRow(row);

            // ==========================================
            // FIX 1: LRN VALIDATION - Check BEFORE normalization
            // ==========================================
            if (!rawLRN) {
                issues.push(`Row ${rowNum}: LRN is required`);
            } else {
                // Remove scientific notation if present
                let cleanLRN = rawLRN;
                if (rawLRN.includes('E+') || rawLRN.includes('e+')) {
                    const num = parseFloat(rawLRN);
                    cleanLRN = num.toFixed(0);
                }
                
                // Check if it contains non-numeric characters
                if (!/^\d+$/.test(cleanLRN)) {
                    issues.push(`Row ${rowNum}: LRN must contain only numbers (found: "${rawLRN}")`);
                } else if (cleanLRN.length !== 12) {
                    issues.push(`Row ${rowNum}: LRN must be exactly 12 digits (found ${cleanLRN.length} digits: "${cleanLRN}")`);
                }
            }

            // ==========================================
            // NAME VALIDATIONS
            // ==========================================
            if (!normalizedRow.lastName) {
                issues.push(`Row ${rowNum}: Last Name is required`);
            } else if (normalizedRow.lastName.length > 100) {
                issues.push(`Row ${rowNum}: Last Name too long (max 100 characters)`);
            }
            
            if (!normalizedRow.firstName) {
                issues.push(`Row ${rowNum}: First Name is required`);
            } else if (normalizedRow.firstName.length > 100) {
                issues.push(`Row ${rowNum}: First Name too long (max 100 characters)`);
            }
            
            if (normalizedRow.middleName && normalizedRow.middleName.length > 100) {
                issues.push(`Row ${rowNum}: Middle Name too long (max 100 characters)`);
            }
            
            // ==========================================
            // GRADE LEVEL VALIDATION
            // ==========================================
            if (!normalizedRow.gradeLevel) {
                issues.push(`Row ${rowNum}: Grade Level is required`);
            } else if (![1, 2, 3, 4, 5, 6].includes(normalizedRow.gradeLevel)) {
                issues.push(`Row ${rowNum}: Invalid Grade Level (must be 7-12, found: "${row['GRADE LEVEL TO ENROLL']}")`);
            }
            
            // ==========================================
            // FIX 2: BIRTHDATE VALIDATION - Better error messages
            // ==========================================
            if (!rawBirthdate) {
                issues.push(`Row ${rowNum}: Birth Date is required`);
            } else if (!normalizedRow.birthdate) {
                issues.push(`Row ${rowNum}: Invalid Birth Date format. Expected format: MM/DD/YYYY (e.g., 01/15/2008). You provided: "${rawBirthdate}"`);
            } else {
                // Additional date validation
                const birthYear = new Date(normalizedRow.birthdate).getFullYear();
                const currentYear = new Date().getFullYear();
                if (birthYear < 1990 || birthYear > currentYear - 10) {
                    this.warnings.push(`Row ${rowNum}: Birth year ${birthYear} seems unusual for high school students`);
                }
            }
            
            // ==========================================
            // FIX 3: SEX/GENDER VALIDATION - Clarify requirements
            // ==========================================
            if (!rawSex) {
                issues.push(`Row ${rowNum}: Sex is required`);
            } else if (!['M', 'F', 'MALE', 'FEMALE', 'Male', 'Female'].includes(rawSex)) {
                issues.push(`Row ${rowNum}: Sex must be "Male" or "Female" (or "M"/"F"). You provided: "${rawSex}"`);
            }
            
            // ==========================================
            // FIX 4: CONTACT NUMBER VALIDATION - Format and length
            // ==========================================
            if (!rawContactNumber) {
                issues.push(`Row ${rowNum}: Contact Number is required`);
            } else {
                // Clean the contact number
                let cleanContact = rawContactNumber;
                if (rawContactNumber.includes('E+') || rawContactNumber.includes('e+')) {
                    const num = parseFloat(rawContactNumber);
                    cleanContact = num.toFixed(0);
                }
                cleanContact = cleanContact.replace(/\D/g, ''); // Remove non-digits
                
                // Check if it's all numeric
                if (!/^\d+$/.test(cleanContact)) {
                    issues.push(`Row ${rowNum}: Contact Number must contain only numbers (found: "${rawContactNumber}")`);
                } else if (cleanContact.length < 10) {
                    issues.push(`Row ${rowNum}: Contact Number too short (minimum 10 digits, found ${cleanContact.length} digits: "${cleanContact}")`);
                } else if (cleanContact.length > 15) {
                    issues.push(`Row ${rowNum}: Contact Number too long (maximum 15 digits, found ${cleanContact.length} digits: "${cleanContact}")`);
                } else if (cleanContact.length === 11 && !cleanContact.startsWith('09')) {
                    this.warnings.push(`Row ${rowNum}: Philippine mobile numbers typically start with 09 (found: "${cleanContact}")`);
                } else if (cleanContact.length === 10 && !cleanContact.startsWith('9')) {
                    this.warnings.push(`Row ${rowNum}: Philippine mobile numbers without country code typically start with 9 (found: "${cleanContact}")`);
                }
            }

            // ==========================================
            // GRADE 11 & 12 STRAND REQUIREMENT
            // ==========================================
            const gradeNum = normalizedRow.gradeLevel;
            if ((gradeNum === 5 || gradeNum === 6) && !normalizedRow.strandID) {
                issues.push(`Row ${rowNum}: Valid strand is required for Grade 11 & 12`);
            }

            // Grades 7-10 should NOT have strand
            if (gradeNum >= 1 && gradeNum <= 4 && normalizedRow.strand) {
                this.warnings.push(`Row ${rowNum}: Strand will be ignored for Junior High (Grades 7-10)`);
            }

            // ==========================================
            // AGE VALIDATION
            // ==========================================
            if (normalizedRow.age !== null) {
                if (normalizedRow.age < 10 || normalizedRow.age > 25) {
                    this.warnings.push(`Row ${rowNum}: Age ${normalizedRow.age} seems unusual for high school`);
                }
            }

            // ==========================================
            // WEIGHT AND HEIGHT VALIDATION
            // ==========================================
            if (normalizedRow.weight !== null && (normalizedRow.weight < 20 || normalizedRow.weight > 150)) {
                this.warnings.push(`Row ${rowNum}: Weight ${normalizedRow.weight}kg seems unusual`);
            }
            
            if (normalizedRow.height !== null && (normalizedRow.height < 1.0 || normalizedRow.height > 2.5)) {
                this.warnings.push(`Row ${rowNum}: Height ${normalizedRow.height}m seems unusual`);
            }

            // ==========================================
            // FIX 5: ZIP CODE VALIDATION
            // ==========================================
            if (rawZipCode) {
                // Clean ZIP code
                const cleanZip = rawZipCode.replace(/\s/g, ''); // Remove spaces
                
                // Check if it contains non-numeric characters
                if (!/^\d+$/.test(cleanZip)) {
                    this.warnings.push(`Row ${rowNum}: ZIP Code should contain only numbers (found: "${rawZipCode}")`);
                } else if (cleanZip.length !== 4) {
                    this.warnings.push(`Row ${rowNum}: Philippine ZIP Code should be 4 digits (found ${cleanZip.length} digits: "${cleanZip}")`);
                }
            }

            // ==========================================
            // ADDRESS VALIDATION - Warnings only
            // ==========================================
            if (!normalizedRow.barangay) {
                this.warnings.push(`Row ${rowNum}: Barangay is recommended`);
            }
            
            if (!normalizedRow.municipality) {
                this.warnings.push(`Row ${rowNum}: Municipality/City is recommended`);
            }

            // ==========================================
            // FINAL DECISION
            // ==========================================
            if (issues.length > 0) {
                this.errors.push(...issues);
            } else {
                this.validRecords.push({
                    row: rowNum,
                    data: normalizedRow
                });
            }
        });
    }

    isValidDate(dateString) {
        if (!dateString) return false;
        
        // Expecting YYYY-MM-DD format (MySQL format) from parseDateString
        const regex = /^\d{4}-\d{2}-\d{2}$/;
        if (!regex.test(dateString)) return false;
        
        const date = new Date(dateString);
        const isValid = date instanceof Date && !isNaN(date);
        
        // Additional check: ensure the date components match
        if (isValid) {
            const [year, month, day] = dateString.split('-').map(Number);
            return date.getFullYear() === year && 
                date.getMonth() === month - 1 && 
                date.getDate() === day;
        }
        
        return false;
    }

    normalizeRow(row) {
        // FIX FOR SCIENTIFIC NOTATION: Convert LRN properly
        const rawLRN = row['LRN'] || '';
        let cleanLRN = '';
        
        if (rawLRN) {
            // Handle scientific notation (e.g., "1.23E+11")
            if (rawLRN.toString().includes('E+') || rawLRN.toString().includes('e+')) {
                // Convert scientific notation to full number
                const num = parseFloat(rawLRN);
                cleanLRN = num.toFixed(0); // Convert to integer string
            } else {
                // Normal string/number
                cleanLRN = rawLRN.toString().trim();
            }
            
            // Remove any non-digit characters
            cleanLRN = cleanLRN.replace(/\D/g, '');
            
            // Pad with zeros if needed (shouldn't happen but just in case)
            if (cleanLRN.length < 12 && cleanLRN.length > 0) {
                cleanLRN = cleanLRN.padStart(12, '0');
            }
        }

        // Parse contact number (remove non-digits, keep leading zero if present)
        let contactNumber = (row['CONTACT NO.'] || '').toString().trim();
        if (contactNumber) {
            // Handle scientific notation for contact numbers too
            if (contactNumber.includes('E+') || contactNumber.includes('e+')) {
                const num = parseFloat(contactNumber);
                contactNumber = num.toFixed(0);
            }
            // Remove non-digits but preserve the number
            contactNumber = contactNumber.replace(/\D/g, '');
        }

        // Parse ZIP code
        let zipCode = (row['ZIP CODE'] || '').toString().trim();
        if (zipCode) {
            zipCode = zipCode.replace(/\D/g, ''); // Remove non-digits
        }

        return {
            lrn: cleanLRN,
            lastName: (row['LAST NAME'] || '').trim().toUpperCase(),
            firstName: (row['FIRST NAME'] || '').trim().toUpperCase(),
            middleName: (row['MIDDLE NAME'] || '').trim().toUpperCase() || null,
            extensionName: null,
            birthdate: this.parseDateString(row['BIRTH DATE']),
            age: this.parseInteger(row['AGE']),
            sex: this.normalizeSex(row['SEX']),
            religion: (row['RELIGION'] || '').trim() || null,
            gradeLevel: this.parseGradeLevel(row['GRADE LEVEL TO ENROLL']),
            learnerType: this.parseLearnerType(row['TYPE OF LEARNER']),
            strand: row['STRAND'] || null,
            strandID: this.parseStrandID(row['STRAND']),
            schoolYear: this.selectedSchoolYear,
            isIPCommunity: this.parseYesNo(row['BELONGING TO ANY INDIGENOUS PEOPLES (IP)']),
            ipCommunitySpecify: null,
            isPWD: false,
            pwdSpecify: null,
            is4PsBeneficiary: this.parseYesNo(row["IS YOUR FAMILY A BENEFICIARY OF 4P'S?"]),
            houseNumber: (row['HOUSE NO.'] || '').trim() || null,
            sitioStreet: (row['STREET NAME'] || '').trim() || null,
            barangay: (row['BARANGAY'] || '').trim() || '',
            municipality: (row['MUNICIPALITY / CITY'] || '').trim() || '',
            province: (row['PROVINCE'] || '').trim() || '',
            zipCode: zipCode || null,
            country: (row['COUNTRY'] || 'Philippines').trim(),
            fatherLastName: null,
            fatherFirstName: null,
            fatherMiddleName: null,
            motherLastName: null,
            motherFirstName: null,
            motherMiddleName: null,
            guardianLastName: (row['GUARDIAN LAST NAME'] || '').trim().toUpperCase() || null,
            guardianFirstName: (row['GUARDIAN FIRST NAME'] || '').trim().toUpperCase() || null,
            guardianMiddleName: (row['GUARDIAN MIDDLE NAME'] || '').trim().toUpperCase() || null,
            contactNumber: contactNumber || '',
            weight: this.parseFloat(row['WEIGHT']),
            height: this.parseFloat(row['HEIGHT'])
        };
    }

    parseInteger(value) {
        if (!value) return null;
        const parsed = parseInt(value);
        return isNaN(parsed) ? null : parsed;
    }

    parseFloat(value) {
        if (!value) return null;
        const parsed = parseFloat(value);
        return isNaN(parsed) ? null : parsed;
    }

    parseDateString(dateStr) {
        if (!dateStr) return null;

        // Excel Date object
        if (dateStr instanceof Date && !isNaN(dateStr)) {
            const y = dateStr.getFullYear();
            const m = String(dateStr.getMonth() + 1).padStart(2, '0');
            const d = String(dateStr.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`; // Still return YYYY-MM-DD for MySQL
        }

        // Convert to string
        const str = dateStr.toString().trim();

        // Excel serial number (e.g. 39575)
        if (/^\d{4,6}$/.test(str)) {
            const excelEpoch = new Date(1899, 11, 30);
            const date = new Date(excelEpoch.getTime() + Number(str) * 86400000);
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        // ✅ PRIMARY FORMAT: MM/DD/YYYY (American format)
        if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(str)) {
            const [mm, dd, yyyy] = str.split('/');
            const month = mm.padStart(2, '0');
            const day = dd.padStart(2, '0');
            
            // Validate the date is real (e.g., not 13/32/2008)
            const testDate = new Date(`${yyyy}-${month}-${day}`);
            if (testDate.getFullYear() === parseInt(yyyy) && 
                testDate.getMonth() === parseInt(mm) - 1 && 
                testDate.getDate() === parseInt(dd)) {
                return `${yyyy}-${month}-${day}`; // Convert to MySQL format
            }
            return null; // Invalid date
        }

        // FALLBACK: YYYY-MM-DD (ISO format) - still support this
        if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
            // Validate it's a real date
            const date = new Date(str);
            if (date instanceof Date && !isNaN(date)) {
                return str;
            }
            return null;
        }

        // FALLBACK: M/D/YYYY or MM/D/YYYY or M/DD/YYYY (flexible)
        if (str.includes('/')) {
            const parts = str.split('/');
            if (parts.length === 3) {
                const [mm, dd, yyyy] = parts;
                if (yyyy && yyyy.length === 4) {
                    const month = mm.padStart(2, '0');
                    const day = dd.padStart(2, '0');
                    
                    // Validate date
                    const testDate = new Date(`${yyyy}-${month}-${day}`);
                    if (testDate.getFullYear() === parseInt(yyyy) && 
                        testDate.getMonth() === parseInt(mm) - 1 && 
                        testDate.getDate() === parseInt(dd)) {
                        return `${yyyy}-${month}-${day}`;
                    }
                }
            }
        }

        return null; // No valid format found
    }

    normalizeSex(sex) {
        if (!sex) return 'Male'; // Default value
        const normalized = sex.toString().trim().toUpperCase();
        if (normalized === 'F' || normalized === 'FEMALE') return 'Female';
        if (normalized === 'M' || normalized === 'MALE') return 'Male';
        return 'Male'; // Default fallback
    }

    parseGradeLevel(gradeStr) {
        if (!gradeStr) return null;
        
        const str = gradeStr.toString().trim();
        const match = str.match(/(\d+)/);
        
        if (match) {
            const num = parseInt(match[1]);
            // Convert Grade 7-12 to 1-6
            if (num >= 7 && num <= 12) {
                return num - 6;
            }
            // Already in 1-6 format
            if (num >= 1 && num <= 6) {
                return num;
            }
        }
        
        return null;
    }

    parseLearnerType(typeStr) {
        if (!typeStr) return 'Regular_New_Student';
        
        const normalized = typeStr.toString().toUpperCase();
        
        if (normalized.includes('OLD STUDENT') || normalized.includes('OLD')) return 'Regular_Old_Student';
        if (normalized.includes('NEW STUDENT') || normalized.includes('NEW')) return 'Regular_New_Student';
        if (normalized.includes('ALS')) return 'Regular_ALS';
        if (normalized.includes('BALIK') && normalized.includes('REGULAR')) return 'Regular_Balik_Aral';
        if (normalized.includes('BALIK ARAL') || normalized.includes('BALIK')) return 'Irregular_Balik_Aral';
        if (normalized.includes('REPEATER')) return 'Irregular_Repeater';
        if (normalized.includes('TRANSFEREE') || normalized.includes('TRANSFER')) return 'Irregular_Transferee';
        
        return 'Regular_New_Student';
    }

    parseStrandID(strandStr) {
        if (!strandStr) return null;
        
        const normalized = strandStr.toString().toUpperCase();
        
        if (normalized.includes('ABM')) return 1;
        if (normalized.includes('HUMSS') || normalized.includes('HUMANITIES')) return 2;
        if (normalized.includes('STEM')) return 3;
        if (normalized.includes('COOKERY') || normalized.includes('HE-') || normalized.includes('HE ') || normalized.includes('BREAD')) return 4;
        if (normalized.includes('ICT') || normalized.includes('CSS') || normalized.includes('COMPUTER')) return 5;
        if (normalized.includes('IA-') || normalized.includes('IA ') || normalized.includes('EIM') || normalized.includes('ELECTRICAL')) return 6;
        
        return null;
    }

    parseYesNo(value) {
        if (!value) return false;
        const normalized = value.toString().toUpperCase().trim();
        return normalized === 'YES' || normalized === 'Y' || normalized === '1' || normalized === 'TRUE';
    }

    // Helper function to safely update element text content
    safeSetTextContent(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = value;
        } else {
            console.warn(`Element with ID '${elementId}' not found`);
        }
    }

    showPreview() {
        document.getElementById('processingSection').classList.add('hidden');
        document.getElementById('previewSection').classList.remove('hidden');
        document.getElementById('importBtn').classList.remove('hidden');

        // Update summary stats - using safe update function
        const totalRecords = this.parsedData.length;
        const validRecords = this.validRecords.length;
        const errorRecords = this.errors.length;
        const warningRecords = this.warnings.length;

        // Summary bar
        this.safeSetTextContent('totalCount', totalRecords);
        this.safeSetTextContent('validCount', validRecords);
        this.safeSetTextContent('errorCount', errorRecords);
        this.safeSetTextContent('warningCount', warningRecords);

        // Statistics cards
        this.safeSetTextContent('totalCountCard', totalRecords);
        this.safeSetTextContent('validCountCard', validRecords);
        this.safeSetTextContent('errorCountCard', errorRecords);
        this.safeSetTextContent('warningCountCard', warningRecords);

        this.safeSetTextContent('importCountLabel', validRecords);
        this.safeSetTextContent('previewSchoolYear', this.selectedSchoolYear);

        // Create a map of row numbers to their errors
        const rowErrorsMap = new Map();
        this.errors.forEach(error => {
            const match = error.match(/Row (\d+):/);
            if (match) {
                const rowNum = parseInt(match[1]);
                if (!rowErrorsMap.has(rowNum)) {
                    rowErrorsMap.set(rowNum, []);
                }
                rowErrorsMap.get(rowNum).push(error.replace(/Row \d+:\s*/, ''));
            }
        });

        // Show/hide issues list
        const issuesList = document.getElementById('issuesList');
        if (this.errors.length > 0 || this.warnings.length > 0) {
            issuesList.classList.remove('hidden');
            
            let issuesHTML = '';
            
            // Show errors
            if (this.errors.length > 0) {
                const displayErrors = this.errors.slice(0, 15);
                issuesHTML += displayErrors.map(error => `
                    <div class="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800 rounded-lg p-3 text-sm text-red-800 dark:text-red-200">
                        <span class="material-icons-outlined text-red-600 dark:text-red-400 text-[16px] align-middle mr-2">error</span>
                        ${this.escapeHtml(error)}
                    </div>
                `).join('');
                
                if (this.errors.length > 15) {
                    issuesHTML += `
                        <div class="text-center text-sm text-gray-500 dark:text-gray-400 py-2">
                            ... and ${this.errors.length - 15} more errors
                        </div>
                    `;
                }
            }
            
            // Show warnings
            if (this.warnings.length > 0) {
                const displayWarnings = this.warnings.slice(0, 10);
                issuesHTML += displayWarnings.map(warning => `
                    <div class="bg-yellow-50 dark:bg-yellow-900/10 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 text-sm text-yellow-800 dark:text-yellow-200">
                        <span class="material-icons-outlined text-yellow-600 dark:text-yellow-400 text-[16px] align-middle mr-2">warning</span>
                        ${this.escapeHtml(warning)}
                    </div>
                `).join('');
                
                if (this.warnings.length > 10) {
                    issuesHTML += `
                        <div class="text-center text-sm text-gray-500 dark:text-gray-400 py-2">
                            ... and ${this.warnings.length - 10} more warnings
                        </div>
                    `;
                }
            }
            
            issuesList.innerHTML = issuesHTML;
        } else {
            issuesList.classList.add('hidden');
        }

        // Build preview table with error highlighting
        const tbody = document.getElementById('previewTableBody');
        const gradeNames = ['', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
        
        // Get all rows (both valid and invalid) and sort by row number
        const allRows = [];
        
        // Add valid records
        this.validRecords.forEach(record => {
            allRows.push({
                rowNum: record.row,
                data: record.data,
                isValid: true,
                errors: []
            });
        });
        
        // Add invalid records
        this.parsedData.forEach((row, index) => {
            const rowNum = index + 2;
            if (!this.validRecords.find(r => r.row === rowNum)) {
                const normalizedRow = this.normalizeRow(row);
                allRows.push({
                    rowNum: rowNum,
                    data: normalizedRow,
                    isValid: false,
                    errors: rowErrorsMap.get(rowNum) || ['Validation failed']
                });
            }
        });
        
        // Sort by row number
        allRows.sort((a, b) => a.rowNum - b.rowNum);
        
        // Show first 50 rows
        const displayRows = allRows.slice(0, 50);
        
        tbody.innerHTML = displayRows.map(item => {
            const row = item.data;
            const rowClass = item.isValid 
                ? 'hover:bg-gray-50/50 dark:hover:bg-gray-800/30' 
                : 'bg-red-50/30 dark:bg-red-900/10 hover:bg-red-50 dark:hover:bg-red-900/20';
            
            // Build error cells
            const lrnCell = this.buildCellWithError(
                row.lrn || '', 
                item.errors.some(e => e.toLowerCase().includes('lrn')),
                item.errors.find(e => e.toLowerCase().includes('lrn'))
            );
            
            const nameCell = this.buildCellWithError(
                row.lastName && row.firstName ? `${row.lastName}, ${row.firstName}` : '',
                item.errors.some(e => e.toLowerCase().includes('name')),
                item.errors.find(e => e.toLowerCase().includes('name'))
            );
            
            const gradeCell = this.buildCellWithError(
                gradeNames[row.gradeLevel] || 'N/A',
                item.errors.some(e => e.toLowerCase().includes('grade')),
                item.errors.find(e => e.toLowerCase().includes('grade'))
            );
            
            const strandCell = this.buildCellWithError(
                row.strand || '-',
                item.errors.some(e => e.toLowerCase().includes('strand')),
                item.errors.find(e => e.toLowerCase().includes('strand'))
            );
            
            const contactCell = this.buildCellWithError(
                row.contactNumber || '',
                item.errors.some(e => e.toLowerCase().includes('contact')),
                item.errors.find(e => e.toLowerCase().includes('contact'))
            );
            
            const guardianName = row.guardianLastName && row.guardianFirstName 
                ? `${row.guardianLastName}, ${row.guardianFirstName}` 
                : '-';
            
            return `
                <tr class="${rowClass}">
                    <td class="px-4 py-3 text-sm text-gray-400">${item.rowNum}</td>
                    ${lrnCell}
                    ${nameCell}
                    ${gradeCell}
                    ${strandCell}
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${this.escapeHtml(guardianName)}</td>
                    ${contactCell}
                    <td class="px-4 py-3">
                        ${item.isValid 
                            ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Valid</span>'
                            : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">Error</span>'
                        }
                    </td>
                </tr>
            `;
        }).join('');

        if (allRows.length > 50) {
            tbody.innerHTML += `
                <tr>
                    <td colspan="8" class="px-4 py-3 text-center text-sm text-gray-500 dark:text-gray-400">
                        ... and ${allRows.length - 50} more records (${this.validRecords.length - displayRows.filter(r => r.isValid).length} valid, ${allRows.filter(r => !r.isValid).length - displayRows.filter(r => !r.isValid).length} with errors)
                    </td>
                </tr>
            `;
        }

        // Update summary bar visibility
        const errorSummary = document.getElementById('errorSummary');
        const validSummary = document.getElementById('validSummary');
        const fixErrorsBtn = document.getElementById('fixErrorsBtn');

        if (this.errors.length > 0) {
            errorSummary.classList.remove('hidden');
            fixErrorsBtn.classList.remove('hidden');
            
            // Bind fix errors button
            fixErrorsBtn.onclick = () => {
                this.resetState();
                document.getElementById('uploadSection').classList.remove('hidden');
                document.getElementById('previewSection').classList.add('hidden');
            };
        } else {
            errorSummary.classList.add('hidden');
            fixErrorsBtn.classList.add('hidden');
        }

        if (this.validRecords.length > 0) {
            validSummary.classList.remove('hidden');
        } else {
            validSummary.classList.add('hidden');
        }
    }

    buildCellWithError(content, hasError, errorMessage) {
        if (!hasError) {
            return `<td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${this.escapeHtml(content)}</td>`;
        }
        
        if (!content || content === 'N/A' || content === '-') {
            // Empty/missing required field
            return `
                <td class="px-4 py-3 relative group">
                    <div class="flex items-center justify-center h-8 bg-red-100/50 dark:bg-red-900/20 rounded border border-red-200 dark:border-red-800 border-dashed">
                        <span class="material-icons-outlined text-sm text-red-500 dark:text-red-400">error_outline</span>
                    </div>
                    <div class="invisible group-hover:visible absolute -top-8 left-1/2 -translate-x-1/2 z-50 px-3 py-1.5 bg-gray-900 dark:bg-gray-800 text-white text-xs rounded whitespace-nowrap pointer-events-none shadow-lg">
                        ${this.escapeHtml(errorMessage || 'Required field')}
                        <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900 dark:border-t-gray-800"></div>
                    </div>
                </td>
            `;
        }
        
        // Invalid value
        return `
            <td class="px-4 py-3 relative group">
                <div class="flex items-center gap-1.5 text-red-600 dark:text-red-400 bg-red-100/50 dark:bg-red-900/20 px-2 py-1 rounded border border-red-200 dark:border-red-800">
                    ${this.escapeHtml(content)}
                    <span class="material-icons-outlined text-sm">warning</span>
                </div>
                <div class="invisible group-hover:visible absolute -top-8 left-1/2 -translate-x-1/2 z-50 px-3 py-1.5 bg-gray-900 dark:bg-gray-800 text-white text-xs rounded whitespace-nowrap pointer-events-none shadow-lg max-w-xs">
                    ${this.escapeHtml(errorMessage || 'Invalid value')}
                    <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900 dark:border-t-gray-800"></div>
                </div>
            </td>
        `;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async importStudents() {
        if (this.validRecords.length === 0) {
            alert('No valid records to import');
            return;
        }

        const confirmMsg = `Import ${this.validRecords.length} student(s) for School Year ${this.selectedSchoolYear}?\n\nThis will create pending enrollments.`;
        
        if (this.errors.length > 0) {
            const errorConfirm = `\n\nNote: There are ${this.errors.length} records with errors that will NOT be imported.\n\nContinue with importing valid records?`;
            if (!confirm(confirmMsg + errorConfirm)) {
                return;
            }
        } else {
            if (!confirm(confirmMsg)) {
                return;
            }
        }

        document.getElementById('previewSection').classList.add('hidden');
        document.getElementById('processingSection').classList.remove('hidden');

        try {
            const students = this.validRecords.map(record => ({
                ...record.data,
                strandID: record.data.strandID || null
            }));
            
            const response = await fetch('../backend/api/enrollment-bulk-import.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    students: students,
                    createdBy: this.currentUser.UserID
                })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Import failed');
            }

            const { total, success, failed, errors } = result.results;
            
            let message = `✓ Import Complete!\n\n`;
            message += `School Year: ${this.selectedSchoolYear}\n`;
            message += `Total Processed: ${total}\n`;
            message += `Successfully Imported: ${success}\n`;
            message += `Failed: ${failed}\n`;
            
            if (errors.length > 0) {
                message += `\nFirst 5 errors:\n`;
                message += errors.slice(0, 5).join('\n');
            }

            alert(message);
            
            // Full reset before closing
            this.resetState();
            this.closeModal();
            
            // Reload page after brief delay
            setTimeout(() => {
                window.location.reload();
            }, 500);

        } catch (error) {
            console.error('Import error:', error);
            alert('❌ Error importing students:\n\n' + error.message);
            this.resetState();
            this.closeModal();
        }
    }

    downloadCsvTemplate() {
        const headers = [
            'GRADE LEVEL TO ENROLL',
            'LAST NAME',
            'FIRST NAME',
            'MIDDLE NAME',
            'TYPE OF LEARNER',
            'STRAND',
            'LRN',
            'BIRTH DATE',
            'AGE',
            'SEX',
            'WEIGHT',
            'HEIGHT',
            'BELONGING TO ANY INDIGENOUS PEOPLES (IP)',
            "IS YOUR FAMILY A BENEFICIARY OF 4P'S?",
            'HOUSE NO.',
            'STREET NAME',
            'BARANGAY',
            'MUNICIPALITY / CITY',
            'PROVINCE',
            'COUNTRY',
            'ZIP CODE',
            'GUARDIAN LAST NAME',
            'GUARDIAN FIRST NAME',
            'GUARDIAN MIDDLE NAME',
            'CONTACT NO.',
            'RELIGION'
        ];

        const sampleRows = [
            [
                'GRADE 11',
                'DELA CRUZ',
                'JUAN',
                'SANTOS',
                'REGULAR - NEW STUDENT',
                'ABM',
                '123456789012',
                '1/15/2008',
                '17',
                'MALE',
                '50',
                '1.65',
                'NO',
                'NO',
                'Block 1 Lot 5',
                'Sampaguita Street',
                'Don Jose',
                'Santa Rosa',
                'Laguna',
                'Philippines',
                '4026',
                'Dela Cruz',
                'Pedro',
                'Reyes',
                '09123456789',
                'Roman Catholic'
            ],
            [
                'GRADE 12',
                'SANTOS',
                'MARIA',
                'GARCIA',
                'REGULAR - OLD STUDENT',
                'STEM',
                '123456789013',
                '3/22/2007',
                '18',
                'FEMALE',
                '48',
                '1.58',
                'NO',
                'YES',
                'House 23',
                'Rizal Avenue',
                'Poblacion',
                'Biñan',
                'Laguna',
                'Philippines',
                '4024',
                'Santos',
                'Jose',
                'Martinez',
                '09234567890',
                'Iglesia ni Cristo'
            ]
        ];

        const csv = [
            headers.join(','),
            ...sampleRows.map(row => row.map(cell => {
                // Escape cells containing commas or quotes
                if (cell.includes(',') || cell.includes('"')) {
                    return `"${cell.replace(/"/g, '""')}"`;
                }
                return cell;
            }).join(','))
        ].join('\n');
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'DJIHS_Student_Import_Template.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    downloadExcelTemplate() {
        // Simply download the pre-made template file
        const templateUrl = '../backend/templates/DJIHS_Student_Import_Template.xlsx';
        
        const a = document.createElement('a');
        a.href = templateUrl;
        a.download = 'DJIHS_Student_Import_Template.xlsx';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        
        console.log('✓ Excel template downloaded from pre-made file');
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        new BulkImportHandler();
    });
} else {
    new BulkImportHandler();
}