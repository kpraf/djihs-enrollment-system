// =====================================================
// Enhanced Bulk Import Handler with School Year Selection
// File: js/bulk-import-handler.js
// =====================================================

class BulkImportHandler {
    constructor() {
        this.uploadedFile = null;
        this.parsedData = [];
        this.validRecords = [];
        this.errors = [];
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
        
        // Reset data
        this.uploadedFile = null;
        this.parsedData = [];
        this.validRecords = [];
        this.errors = [];
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
            alert('Invalid file type. Please upload a CSV or Excel file.');
            return;
        }

        // Validate file size (10MB max)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size exceeds 10MB limit');
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
                    this.parsedData = results.data;
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
        alert('Excel file support coming soon. Please convert to CSV format.');
        this.closeModal();
    }

    validateData() {
        this.validRecords = [];
        this.errors = [];

        this.parsedData.forEach((row, index) => {
            const issues = [];
            const rowNum = index + 2; // +2 for header and 0-index

            // Normalize field names
            const normalizedRow = this.normalizeRow(row);

            // Required fields validation
            if (!normalizedRow.lrn || normalizedRow.lrn.length !== 12) {
                issues.push(`Row ${rowNum}: Invalid LRN (must be 12 digits)`);
            }
            if (!normalizedRow.lastName) {
                issues.push(`Row ${rowNum}: Last Name is required`);
            }
            if (!normalizedRow.firstName) {
                issues.push(`Row ${rowNum}: First Name is required`);
            }
            if (!normalizedRow.gradeLevel) {
                issues.push(`Row ${rowNum}: Grade Level is required`);
            }
            if (!normalizedRow.birthdate) {
                issues.push(`Row ${rowNum}: Birth Date is required`);
            }
            if (!normalizedRow.sex) {
                issues.push(`Row ${rowNum}: Sex is required`);
            }
            if (!normalizedRow.contactNumber) {
                issues.push(`Row ${rowNum}: Contact Number is required`);
            }

            // Grade 11 & 12 must have strand
            const gradeNum = normalizedRow.gradeLevel;
            if ((gradeNum === 5 || gradeNum === 6) && !normalizedRow.strand) {
                issues.push(`Row ${rowNum}: Strand is required for Grade 11 & 12`);
            }

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

    normalizeRow(row) {
        return {
            lrn: (row['LRN'] || '').toString().trim(),
            lastName: (row['LAST NAME'] || '').trim(),
            firstName: (row['FIRST NAME'] || '').trim(),
            middleName: (row['MIDDLE NAME'] || '').trim() || null,
            extensionName: null,
            birthdate: this.parseDateString(row['BIRTH DATE']),
            age: parseInt(row['AGE']) || null,
            sex: this.normalizeSex(row['SEX']),
            religion: (row['RELIGION'] || '').trim() || null,
            gradeLevel: this.parseGradeLevel(row['GRADE LEVEL TO ENROLL']),
            learnerType: this.parseLearnerType(row['TYPE OF LEARNER']),
            strand: row['STRAND'] || null,
            strandID: this.parseStrandID(row['STRAND']),
            schoolYear: this.selectedSchoolYear, // Use selected school year
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
            zipCode: (row['ZIP CODE'] || '').toString().trim() || null,
            country: (row['COUNTRY'] || 'Philippines').trim(),
            fatherLastName: null,
            fatherFirstName: null,
            fatherMiddleName: null,
            motherLastName: null,
            motherFirstName: null,
            motherMiddleName: null,
            guardianLastName: (row['GUARDIAN LAST NAME'] || '').trim() || null,
            guardianFirstName: (row['GUARDIAN FIRST NAME'] || '').trim() || null,
            guardianMiddleName: (row['GUARDIAN MIDDLE NAME'] || '').trim() || null,
            contactNumber: (row['CONTACT NO.'] || '').toString().trim() || '',
            weight: parseFloat(row['WEIGHT']) || null,
            height: parseFloat(row['HEIGHT']) || null
        };
    }

    parseDateString(dateStr) {
        if (!dateStr) return null;
        
        const parts = dateStr.split('/');
        if (parts.length === 3) {
            const month = parts[0].padStart(2, '0');
            const day = parts[1].padStart(2, '0');
            const year = parts[2];
            return `${year}-${month}-${day}`;
        }
        
        return dateStr;
    }

    normalizeSex(sex) {
        if (!sex) return 'Male';
        const normalized = sex.trim().toUpperCase();
        if (normalized === 'F' || normalized === 'FEMALE') return 'Female';
        return 'Male';
    }

    parseGradeLevel(gradeStr) {
        if (!gradeStr) return null;
        const match = gradeStr.match(/(\d+)/);
        if (match) {
            const num = parseInt(match[1]);
            if (num >= 7 && num <= 12) {
                return num - 6;
            }
        }
        return null;
    }

    parseLearnerType(typeStr) {
        if (!typeStr) return 'Regular_New_Student';
        
        const normalized = typeStr.toUpperCase();
        
        if (normalized.includes('OLD STUDENT')) return 'Regular_Old_Student';
        if (normalized.includes('NEW STUDENT')) return 'Regular_New_Student';
        if (normalized.includes('ALS')) return 'Regular_ALS';
        if (normalized.includes('BALIK') && normalized.includes('REGULAR')) return 'Regular_Balik_Aral';
        if (normalized.includes('BALIK')) return 'Irregular_Balik_Aral';
        if (normalized.includes('REPEATER')) return 'Irregular_Repeater';
        if (normalized.includes('TRANSFEREE')) return 'Irregular_Transferee';
        
        return 'Regular_New_Student';
    }

    parseStrandID(strandStr) {
        if (!strandStr) return null;
        
        const normalized = strandStr.toUpperCase();
        
        if (normalized.includes('ABM')) return 1;
        if (normalized.includes('HUMSS')) return 2;
        if (normalized.includes('STEM')) return 3;
        if (normalized.includes('COOKERY') || normalized.includes('HE-')) return 4;
        if (normalized.includes('ICT') || normalized.includes('CSS')) return 5;
        if (normalized.includes('IA-') || normalized.includes('EIM')) return 6;
        
        return null;
    }

    parseYesNo(value) {
        if (!value) return false;
        const normalized = value.toString().toUpperCase().trim();
        return normalized === 'YES' || normalized === 'Y' || normalized === '1';
    }

    showPreview() {
        document.getElementById('processingSection').classList.add('hidden');
        document.getElementById('previewSection').classList.remove('hidden');
        document.getElementById('importBtn').classList.remove('hidden');

        // Update summary
        document.getElementById('totalCount').textContent = this.parsedData.length;
        document.getElementById('validCount').textContent = this.validRecords.length;
        document.getElementById('errorCount').textContent = this.errors.length;
        document.getElementById('warningCount').textContent = 0;
        document.getElementById('importCountLabel').textContent = this.validRecords.length;

        // Display selected school year
        document.getElementById('previewSchoolYear').textContent = this.selectedSchoolYear;

        // Show errors if any
        if (this.errors.length > 0) {
            const issuesList = document.getElementById('issuesList');
            issuesList.classList.remove('hidden');
            issuesList.innerHTML = this.errors.slice(0, 20).map(error => `
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">
                    <span class="material-icons-outlined text-red-600 text-[16px] align-middle mr-2">error</span>
                    ${error}
                </div>
            `).join('');
            
            if (this.errors.length > 20) {
                issuesList.innerHTML += `
                    <div class="text-center text-sm text-gray-500 py-2">
                        ... and ${this.errors.length - 20} more errors
                    </div>
                `;
            }
        }

        // Show preview table
        const tbody = document.getElementById('previewTableBody');
        const gradeNames = ['', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
        
        tbody.innerHTML = this.validRecords.slice(0, 50).map(record => {
            const row = record.data;
            return `
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm text-gray-900">${record.row}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">${row.lrn}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">${row.lastName}, ${row.firstName}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">${gradeNames[row.gradeLevel] || 'N/A'}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">${row.strand || '-'}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                            Valid
                        </span>
                    </td>
                </tr>
            `;
        }).join('');

        if (this.validRecords.length > 50) {
            tbody.innerHTML += `
                <tr>
                    <td colspan="6" class="px-4 py-3 text-center text-sm text-gray-500">
                        ... and ${this.validRecords.length - 50} more valid records
                    </td>
                </tr>
            `;
        }
    }

    async importStudents() {
        if (this.validRecords.length === 0) {
            alert('No valid records to import');
            return;
        }

        if (!confirm(`Import ${this.validRecords.length} student(s) for School Year ${this.selectedSchoolYear}?\n\nThis will create pending enrollments.`)) {
            return;
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
            message += `Total: ${total}\n`;
            message += `Success: ${success}\n`;
            message += `Failed: ${failed}\n`;
            
            if (errors.length > 0) {
                message += `\nFirst 5 errors:\n`;
                message += errors.slice(0, 5).join('\n');
            }

            alert(message);
            this.closeModal();
            window.location.reload();

        } catch (error) {
            console.error('Import error:', error);
            alert('❌ Error importing students:\n\n' + error.message);
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
            'CONTACT NO.'
        ];

        const sampleRow = [
            'GRADE 11',
            'PRAFEROSA',
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
            '09123456789'
        ];

        const csv = [headers.join(','), sampleRow.join(',')].join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
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
        alert('Excel template download requires SheetJS library.\nPlease use the CSV template for now.');
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