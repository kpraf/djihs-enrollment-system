// =====================================================
// Enhanced Enrollment Form Handler with LRN Validation
// File: js/enrollment-form-handler.js
// =====================================================

class EnrollmentFormHandler {
    constructor() {
        this.formData = {};
        this.currentUser = null;
        this.submitBtn = null;
        this.lrnSearchTimeout = null;
        this.studentEnrollmentHistory = [];
        this.latestEnrollment = null;
        this.init();
    }

    init() {
        this.currentUser = this.getCurrentUser();
        
        if (!this.currentUser) {
            alert('You must be logged in to access this page');
            window.location.href = '../login.html';
            return;
        }

        console.log('Enrollment form handler initialized');
        console.log('Current user:', this.currentUser);

        this.bindEventListeners();
        this.setupFormValidation();
        this.setupAutoCalculateAge();
        this.setupTrackVisibility();
        this.setupLRNAutofill();
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
        const allButtons = document.querySelectorAll('button');
        console.log('Found buttons:', allButtons.length);

        let submitBtn = null;
        let resetBtn = null;

        allButtons.forEach((button, index) => {
            const buttonText = button.textContent.trim();
            console.log(`Button ${index}:`, buttonText);

            if (buttonText.includes('Submit Enrollment')) {
                submitBtn = button;
                console.log('Submit button found!');
            } else if (buttonText.includes('Reset Form')) {
                resetBtn = button;
                console.log('Reset button found!');
            }
        });

        if (submitBtn) {
            this.submitBtn = submitBtn;
            submitBtn.addEventListener('click', (e) => {
                console.log('Submit button clicked!');
                this.handleSubmit(e);
            });
            console.log('Submit button event listener attached');
        } else {
            console.error('Submit button not found!');
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', (e) => {
                console.log('Reset button clicked!');
                this.handleReset(e);
            });
            console.log('Reset button event listener attached');
        } else {
            console.error('Reset button not found!');
        }

        const gradeLevelSelect = document.querySelectorAll('select')[0];
        if (gradeLevelSelect) {
            gradeLevelSelect.addEventListener('change', (e) => this.handleGradeLevelChange(e));
            console.log('Grade level change listener attached');
        }
    }

    setupLRNAutofill() {
        const lrnInput = document.getElementById('lrn');
        
        if (!lrnInput) {
            console.error('LRN input not found!');
            return;
        }

        // Create loading indicator
        const loadingIndicator = document.createElement('div');
        loadingIndicator.id = 'lrnLoadingIndicator';
        loadingIndicator.className = 'hidden absolute right-3 top-1/2 -translate-y-1/2';
        loadingIndicator.innerHTML = '<div class="loading-spinner w-5 h-5"></div>';
        
        // Make parent relative
        const lrnParent = lrnInput.parentElement;
        lrnParent.style.position = 'relative';
        lrnParent.appendChild(loadingIndicator);

        // Add event listener for LRN input
        lrnInput.addEventListener('input', (e) => {
            const lrn = e.target.value.trim();
            
            // Clear previous timeout
            if (this.lrnSearchTimeout) {
                clearTimeout(this.lrnSearchTimeout);
            }

            // Only search if LRN is 12 digits
            if (lrn.length === 12 && /^\d{12}$/.test(lrn)) {
                // Debounce the search
                this.lrnSearchTimeout = setTimeout(() => {
                    this.searchStudentByLRN(lrn);
                }, 500);
            } else {
                // Clear enrollment history if LRN is incomplete
                this.studentEnrollmentHistory = [];
                this.latestEnrollment = null;
                this.removeEnrollmentHistoryDisplay();
            }
        });

        console.log('LRN autofill setup complete');
    }

    async searchStudentByLRN(lrn) {
        const loadingIndicator = document.getElementById('lrnLoadingIndicator');
        
        try {
            // Show loading
            if (loadingIndicator) {
                loadingIndicator.classList.remove('hidden');
            }

            console.log('Searching for student with LRN:', lrn);

            const response = await fetch(`../backend/api/student-by-lrn.php?lrn=${lrn}`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.student) {
                // Student found - store enrollment history
                this.studentEnrollmentHistory = result.enrollmentHistory || [];
                this.latestEnrollment = result.latestEnrollment;
                
                console.log('Student found:', result.student);
                console.log('Enrollment history:', this.studentEnrollmentHistory);
                
                // Autofill form
                this.autofillStudentData(result.student);
                
                // Display enrollment history
                this.displayEnrollmentHistory();
                
                // Show notification
                this.showNotification('Student found! Form autofilled with existing data.', 'success');
            } else {
                // Student not found
                console.log('Student not found with LRN:', lrn);
                this.studentEnrollmentHistory = [];
                this.latestEnrollment = null;
                this.removeEnrollmentHistoryDisplay();
                this.showNotification('LRN not found in system. Please enter student details.', 'info');
            }

        } catch (error) {
            console.error('Error searching for student:', error);
            this.showNotification('Error searching for student. Please try again.', 'error');
        } finally {
            // Hide loading
            if (loadingIndicator) {
                loadingIndicator.classList.add('hidden');
            }
        }
    }

    displayEnrollmentHistory() {
        // Remove existing history display
        this.removeEnrollmentHistoryDisplay();
        
        if (this.studentEnrollmentHistory.length === 0) return;

        // Create history display
        const historyDiv = document.createElement('div');
        historyDiv.id = 'enrollmentHistoryDisplay';
        historyDiv.className = 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4';
        
        let historyHTML = `
            <h4 class="text-sm font-semibold text-blue-900 dark:text-blue-100 mb-3 flex items-center gap-2">
                <span class="material-icons-outlined text-[20px]">history_edu</span>
                Enrollment History for this Student
            </h4>
            <div class="space-y-2">
        `;

        this.studentEnrollmentHistory.forEach((enrollment, index) => {
            const isLatest = index === 0;
            const statusColor = enrollment.Status === 'Confirmed' ? 'text-green-600' : 
                               enrollment.Status === 'Pending' ? 'text-yellow-600' : 
                               'text-gray-600';
            
            historyHTML += `
                <div class="bg-white dark:bg-slate-800 rounded-lg p-3 ${isLatest ? 'border-2 border-blue-400' : ''}">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-semibold text-slate-800 dark:text-white">${enrollment.AcademicYear}</span>
                                ${isLatest ? '<span class="text-xs bg-blue-500 text-white px-2 py-0.5 rounded-full">Latest</span>' : ''}
                            </div>
                            <div class="text-sm text-slate-600 dark:text-slate-300">
                                <span class="font-medium">${enrollment.GradeLevelName}</span>
                                ${enrollment.StrandName ? ` - ${enrollment.StrandName}` : ''}
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                ${enrollment.LearnerType.replace(/_/g, ' ')} • ${enrollment.EnrollmentType}
                            </div>
                        </div>
                        <span class="text-xs font-semibold ${statusColor}">${enrollment.Status}</span>
                    </div>
                </div>
            `;
        });

        historyHTML += `</div>`;
        historyDiv.innerHTML = historyHTML;

        // Insert after LRN notification
        const pageHeader = document.querySelector('.flex.flex-col.sm\\:flex-row.sm\\:items-center');
        if (pageHeader) {
            const existingNotif = document.getElementById('lrnNotification');
            if (existingNotif) {
                existingNotif.insertAdjacentElement('afterend', historyDiv);
            } else {
                pageHeader.insertAdjacentElement('afterend', historyDiv);
            }
        }
    }

    removeEnrollmentHistoryDisplay() {
        const existingDisplay = document.getElementById('enrollmentHistoryDisplay');
        if (existingDisplay) {
            existingDisplay.remove();
        }
    }

    autofillStudentData(student) {
        console.log('Autofilling form with student data:', student);

        // Learner's Information
        const inputs = Array.from(document.querySelectorAll('input'));
        
        // Name fields
        const lastNameInput = this.getInputByPlaceholder(inputs, 'Dela Cruz');
        if (lastNameInput) lastNameInput.value = student.LastName || '';
        
        const firstNameInput = this.getInputByPlaceholder(inputs, 'Juan');
        if (firstNameInput) firstNameInput.value = student.FirstName || '';
        
        const middleNameInput = this.getInputByPlaceholder(inputs, 'Santos');
        if (middleNameInput) middleNameInput.value = student.MiddleName || '';
        
        const extensionInput = this.getInputByPlaceholder(inputs, 'Jr.');
        if (extensionInput) extensionInput.value = student.ExtensionName || '';

        // Birth date and age
        const birthdateInput = document.querySelector('input[type="date"]');
        if (birthdateInput && student.BirthDate) {
            birthdateInput.value = student.BirthDate;
            birthdateInput.dispatchEvent(new Event('change'));
        }

        // Sex
        const sexSelect = document.querySelectorAll('select')[1];
        if (sexSelect && student.Sex) {
            sexSelect.value = student.Sex;
        }

        // Religion
        const religionInput = this.getInputByPlaceholder(inputs, 'Roman Catholic');
        if (religionInput) religionInput.value = student.Religion || '';

        // IP Community
        if (student.IsIPCommunity !== null && student.IsIPCommunity !== undefined) {
            const ipRadios = document.querySelectorAll('input[name="isIPCommunity"]');
            ipRadios.forEach(radio => {
                if ((radio.value === '1' && student.IsIPCommunity) || 
                    (radio.value === '0' && !student.IsIPCommunity)) {
                    radio.checked = true;
                }
            });
        }

        // Disability
        if (student.IsPWD !== null && student.IsPWD !== undefined) {
            const pwdRadios = document.querySelectorAll('input[name="isPWD"]');
            pwdRadios.forEach(radio => {
                if ((radio.value === '1' && student.IsPWD) || 
                    (radio.value === '0' && !student.IsPWD)) {
                    radio.checked = true;
                }
            });
        }

        // 4Ps Beneficiary
        if (student.Is4PsBeneficiary !== null && student.Is4PsBeneficiary !== undefined) {
            const fourPsRadios = document.querySelectorAll('input[name="is4PsBeneficiary"]');
            fourPsRadios.forEach(radio => {
                if ((radio.value === '1' && student.Is4PsBeneficiary) || 
                    (radio.value === '0' && !student.Is4PsBeneficiary)) {
                    radio.checked = true;
                }
            });
        }

        // Weight and Height
        const weightInput = document.getElementById('weight');
        if (weightInput) weightInput.value = student.Weight || '';
        
        const heightInput = document.getElementById('height');
        if (heightInput) heightInput.value = student.Height || '';

        // Address
        const houseNumberInput = document.getElementById('houseNumber');
        if (houseNumberInput) houseNumberInput.value = student.HouseNumber || '';
        
        const sitioStreetInput = document.getElementById('sitioStreet');
        if (sitioStreetInput) sitioStreetInput.value = student.SitioStreet || '';
        
        const barangayInput = document.getElementById('barangay');
        if (barangayInput) barangayInput.value = student.Barangay || '';
        
        const municipalityInput = document.getElementById('municipality');
        if (municipalityInput) municipalityInput.value = student.Municipality || '';
        
        const provinceInput = document.getElementById('province');
        if (provinceInput) provinceInput.value = student.Province || '';
        
        const zipCodeInput = document.getElementById('zipCode');
        if (zipCodeInput) zipCodeInput.value = student.ZipCode || '';
        
        const countryInput = document.getElementById('country');
        if (countryInput) countryInput.value = student.Country || 'Philippines';

        // Parent/Guardian Information
        const fatherLastNameInput = document.getElementById('fatherLastName');
        if (fatherLastNameInput) fatherLastNameInput.value = student.FatherLastName || '';
        
        const fatherFirstNameInput = document.getElementById('fatherFirstName');
        if (fatherFirstNameInput) fatherFirstNameInput.value = student.FatherFirstName || '';
        
        const fatherMiddleNameInput = document.getElementById('fatherMiddleName');
        if (fatherMiddleNameInput) fatherMiddleNameInput.value = student.FatherMiddleName || '';
        
        const motherLastNameInput = document.getElementById('motherLastName');
        if (motherLastNameInput) motherLastNameInput.value = student.MotherLastName || '';
        
        const motherFirstNameInput = document.getElementById('motherFirstName');
        if (motherFirstNameInput) motherFirstNameInput.value = student.MotherFirstName || '';
        
        const motherMiddleNameInput = document.getElementById('motherMiddleName');
        if (motherMiddleNameInput) motherMiddleNameInput.value = student.MotherMiddleName || '';
        
        const guardianLastNameInput = document.getElementById('guardianLastName');
        if (guardianLastNameInput) guardianLastNameInput.value = student.GuardianLastName || '';
        
        const guardianFirstNameInput = document.getElementById('guardianFirstName');
        if (guardianFirstNameInput) guardianFirstNameInput.value = student.GuardianFirstName || '';
        
        const guardianMiddleNameInput = document.getElementById('guardianMiddleName');
        if (guardianMiddleNameInput) guardianMiddleNameInput.value = student.GuardianMiddleName || '';
        
        const contactNumberInput = document.getElementById('contactNumber');
        if (contactNumberInput) contactNumberInput.value = student.ContactNumber || '';

        console.log('Form autofill complete');
    }

    showNotification(message, type = 'info') {
        const existingNotif = document.getElementById('lrnNotification');
        if (existingNotif) {
            existingNotif.remove();
        }

        const notification = document.createElement('div');
        notification.id = 'lrnNotification';
        
        let bgColor, borderColor, textColor, icon;
        
        switch(type) {
            case 'success':
                bgColor = 'bg-green-50';
                borderColor = 'border-green-200';
                textColor = 'text-green-800';
                icon = 'check_circle';
                break;
            case 'error':
                bgColor = 'bg-red-50';
                borderColor = 'border-red-200';
                textColor = 'text-red-800';
                icon = 'error';
                break;
            default:
                bgColor = 'bg-blue-50';
                borderColor = 'border-blue-200';
                textColor = 'text-blue-800';
                icon = 'info';
        }

        notification.className = `${bgColor} ${borderColor} ${textColor} border rounded-lg p-4 mb-4 flex items-center gap-3 transition-all`;
        notification.innerHTML = `
            <span class="material-icons-outlined">${icon}</span>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
                <span class="material-icons-outlined text-[20px]">close</span>
            </button>
        `;

        const pageHeader = document.querySelector('.flex.flex-col.sm\\:flex-row.sm\\:items-center');
        if (pageHeader) {
            pageHeader.insertAdjacentElement('afterend', notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }
    }

    setupAutoCalculateAge() {
        const birthdateInput = document.querySelector('input[type="date"]');
        const ageInput = document.querySelector('input[placeholder*="16"]');

        if (birthdateInput && ageInput) {
            birthdateInput.addEventListener('change', (e) => {
                const birthdate = new Date(e.target.value);
                const today = new Date();
                let age = today.getFullYear() - birthdate.getFullYear();
                const monthDiff = today.getMonth() - birthdate.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthdate.getDate())) {
                    age--;
                }
                
                ageInput.value = age;
                console.log('Age calculated:', age);
            });
        }
    }

    setupTrackVisibility() {
        const gradeLevelSelect = document.querySelectorAll('select')[0];
        if (gradeLevelSelect) {
            this.handleGradeLevelChange({ target: gradeLevelSelect });
        }
    }

    handleGradeLevelChange(e) {
        const gradeLevel = e.target.value;
        const strandSection = document.getElementById('strandSection');
        
        if (strandSection) {
            if (gradeLevel === 'Grade 11' || gradeLevel === 'Grade 12') {
                strandSection.classList.remove('hidden');
            } else {
                strandSection.classList.add('hidden');
                document.querySelectorAll('input[name="track"]').forEach(radio => {
                    radio.checked = false;
                });
            }
        }
    }

    setupFormValidation() {
        // Real-time validation can be added here
    }

    collectFormData() {
        console.log('Collecting form data...');
        const formData = {};
        const inputs = document.querySelectorAll('input');

        formData.schoolYear = document.getElementById('schoolYear')?.value.trim() || '';
        formData.gradeLevel = this.getGradeLevelID(document.querySelectorAll('select')[0]?.value);
        formData.lrn = document.getElementById('lrn')?.value.trim() || null;

        const learnerTypeRadio = document.querySelector('input[name="learner-type"]:checked');
        if (learnerTypeRadio) {
            const learnerLabel = learnerTypeRadio.nextSibling?.textContent.trim() || 
                               learnerTypeRadio.parentElement.textContent.trim();
            const parentDiv = learnerTypeRadio.closest('.grid');
            const isRegular = parentDiv?.querySelector('p')?.textContent.includes('REGULAR');
            formData.learnerType = this.mapLearnerType(learnerLabel, isRegular);
        } else {
            formData.learnerType = null;
        }

        const trackRadio = document.querySelector('input[name="track"]:checked');
        if (trackRadio) {
            const trackLabel = trackRadio.nextSibling?.textContent.trim() || 
                             trackRadio.parentElement.textContent.trim();
            formData.strandID = this.mapTrackToStrandID(trackLabel);
        } else {
            formData.strandID = null;
        }

        const nameInputs = Array.from(inputs);
        formData.lastName = document.getElementById('lastName')?.value.trim() || '';
        formData.firstName = document.getElementById('firstName')?.value.trim() || '';
        formData.middleName = document.getElementById('middleName')?.value.trim() || null;
        formData.extensionName = document.getElementById('extensionName')?.value.trim() || null;
        formData.birthdate = document.getElementById('birthdate')?.value || '';
        formData.age = parseInt(this.getInputByPlaceholder(nameInputs, '16')?.value) || null;
        formData.sex = document.getElementById('sex')?.value || 'Male';
        formData.religion = document.getElementById('religion')?.value.trim() || null;

        const ipRadio = document.querySelector('input[name="isIPCommunity"]:checked');
        formData.isIPCommunity = ipRadio?.value === '1';
        formData.ipCommunitySpecify = document.getElementById('ipCommunitySpecify')?.value.trim() || null;

        const disabilityRadio = document.querySelector('input[name="isPWD"]:checked');
        formData.isPWD = disabilityRadio?.value === '1';
        formData.pwdSpecify = document.getElementById('pwdSpecify')?.value.trim() || null;

        const fourPsRadio = document.querySelector('input[name="is4PsBeneficiary"]:checked');
        formData.is4PsBeneficiary = fourPsRadio?.value === '1';

        formData.weight = document.getElementById('weight')?.value || null;
        formData.height = document.getElementById('height')?.value || null;

        formData.houseNumber = document.getElementById('houseNumber')?.value.trim() || null;
        formData.sitioStreet = document.getElementById('sitioStreet')?.value.trim() || null;
        formData.barangay = document.getElementById('barangay')?.value.trim() || '';
        formData.municipality = document.getElementById('municipality')?.value.trim() || '';
        formData.province = document.getElementById('province')?.value.trim() || '';
        formData.zipCode = document.getElementById('zipCode')?.value.trim() || null;
        formData.country = document.getElementById('country')?.value.trim() || 'Philippines';

        formData.fatherLastName = document.getElementById('fatherLastName')?.value.trim() || null;
        formData.fatherFirstName = document.getElementById('fatherFirstName')?.value.trim() || null;
        formData.fatherMiddleName = document.getElementById('fatherMiddleName')?.value.trim() || null;
        formData.motherLastName = document.getElementById('motherLastName')?.value.trim() || null;
        formData.motherFirstName = document.getElementById('motherFirstName')?.value.trim() || null;
        formData.motherMiddleName = document.getElementById('motherMiddleName')?.value.trim() || null;
        formData.guardianLastName = document.getElementById('guardianLastName')?.value.trim() || null;
        formData.guardianFirstName = document.getElementById('guardianFirstName')?.value.trim() || null;
        formData.guardianMiddleName = document.getElementById('guardianMiddleName')?.value.trim() || null;
        formData.contactNumber = document.getElementById('contactNumber')?.value.trim() || '';

        formData.enrollmentType = 'Regular';
        formData.encodedDate = new Date().toISOString();

        console.log('Complete form data:', formData);
        return formData;
    }

    getInputByPlaceholder(inputs, placeholder) {
        return Array.from(inputs).find(input => 
            input.placeholder?.includes(placeholder)
        );
    }

    getGradeLevelID(gradeLevelName) {
        const mapping = {
            'Grade 7': 1,
            'Grade 8': 2,
            'Grade 9': 3,
            'Grade 10': 4,
            'Grade 11': 5,
            'Grade 12': 6
        };
        return mapping[gradeLevelName] || null;
    }

    mapLearnerType(label, isRegular) {
        label = label.replace(/\s+/g, ' ').trim();
        
        const mapping = {
            'Old Student': 'Regular_Old_Student',
            'New Student': 'Regular_New_Student',
            'ALS': 'Regular_ALS',
            'Balik-aral': isRegular ? 'Regular_Balik_Aral' : 'Irregular_Balik_Aral',
            'Repeater': 'Irregular_Repeater',
            'Transferee': 'Irregular_Transferee'
        };
        return mapping[label] || null;
    }

    mapTrackToStrandID(trackLabel) {
        trackLabel = trackLabel.replace(/\s+/g, ' ').trim();
        
        const mapping = {
            'ABM': 1,
            'HUMSS': 2,
            'STEM': 3,
            'HE-COOKERY/BPP/FBS NCII': 4,
            'ICT-CSS NCII': 5,
            'IA-EIM NCII': 6
        };
        return mapping[trackLabel] || null;
    }

    validateForm(formData) {
        const errors = [];

        // Required fields
        if (!formData.schoolYear) {
            errors.push('School Year is required');
        } else if (!/^\d{4}-\d{4}$/.test(formData.schoolYear)) {
            errors.push('School Year must be in YYYY-YYYY format');
        } else {
            const [start, end] = formData.schoolYear.split('-').map(Number);
            if (end !== start + 1) {
                errors.push('School Year must be consecutive (e.g., 2025-2026)');
            }
        }
        
        if (!formData.gradeLevel) errors.push('Grade Level is required');
        if (!formData.learnerType) errors.push('Type of Learner must be selected');
        if (!formData.lastName) errors.push('Last Name is required');
        if (!formData.firstName) errors.push('First Name is required');
        if (!formData.birthdate) errors.push('Birthdate is required');
        if (!formData.age) errors.push('Age is required');
        if (!formData.sex) errors.push('Sex is required');
        if (!formData.barangay) errors.push('Barangay is required');
        if (!formData.municipality) errors.push('Municipality/City is required');
        if (!formData.province) errors.push('Province is required');
        if (!formData.contactNumber) errors.push('Contact Number is required');

        // Track required for Grade 11 & 12
        if ((formData.gradeLevel === 5 || formData.gradeLevel === 6) && !formData.strandID) {
            errors.push('Track selection is required for Grade 11 & 12');
        }

        // Validate strand consistency for Senior High students
        if (this.latestEnrollment && (formData.gradeLevel === 5 || formData.gradeLevel === 6)) {
            const previousGrade = this.latestEnrollment.GradeLevelID;
            const previousStrand = this.latestEnrollment.StrandID;
            
            // If previous enrollment was Grade 11 and current is Grade 12
            if (previousGrade === 5 && formData.gradeLevel === 6) {
                if (previousStrand && formData.strandID && previousStrand !== formData.strandID) {
                    const strandNames = {
                        1: 'ABM', 2: 'HUMSS', 3: 'STEM',
                        4: 'HE-COOKERY', 5: 'ICT-CSS', 6: 'IA-EIM'
                    };
                    errors.push(
                        `Strand mismatch: Student was enrolled in ${strandNames[previousStrand]} for Grade 11. ` +
                        `Cannot change to ${strandNames[formData.strandID]} for Grade 12.`
                    );
                }
            }
        }

        // Check for duplicate enrollment in same academic year
        if (formData.lrn && this.studentEnrollmentHistory.length > 0) {
            const duplicateEnrollment = this.studentEnrollmentHistory.find(
                enrollment => enrollment.AcademicYear === formData.schoolYear
            );
            
            if (duplicateEnrollment) {
                errors.push(
                    `Duplicate enrollment detected: Student with LRN ${formData.lrn} is already enrolled ` +
                    `for ${formData.schoolYear} (${duplicateEnrollment.GradeLevelName}${duplicateEnrollment.StrandName ? ' - ' + duplicateEnrollment.StrandName : ''}). ` +
                    `Status: ${duplicateEnrollment.Status}`
                );
            }
        }

        // At least one parent/guardian
        const hasParent = formData.fatherFirstName || formData.motherFirstName || formData.guardianFirstName;
        if (!hasParent) {
            errors.push('At least one parent or guardian name is required');
        }
        
        if (!formData.enrollmentType) {
            errors.push('Enrollment Type is required');
        }

        if (formData.learnerType === 'Irregular_Transferee' && formData.enrollmentType !== 'Transferee') {
            formData.enrollmentType = 'Transferee';
        }

        return errors;
    }

    async handleSubmit(e) {
        e.preventDefault();
        console.log('=== FORM SUBMISSION STARTED ===');

        const formData = this.collectFormData();

        // Validate first
        const errors = this.validateForm(formData);
        if (errors.length > 0) {
            alert('Please fix the following errors:\n\n' + errors.join('\n'));
            return;
        }

        // IP community prompt
        if (formData.isIPCommunity && !formData.ipCommunitySpecify) {
            formData.ipCommunitySpecify = prompt('Please specify IP Community:');
            if (!formData.ipCommunitySpecify) {
                alert('IP Community specification is required');
                return;
            }
        }

        // Confirmation
        const gradeText = ['', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'][formData.gradeLevel];
        const confirmMsg = `Please confirm enrollment submission:\n\n` +
            `Name: ${formData.firstName} ${formData.lastName}\n` +
            `Grade Level: ${gradeText}\n` +
            `School Year: ${formData.schoolYear}\n\n` +
            `Submit this enrollment form?`;

        if (!confirm(confirmMsg)) {
            return;
        }

        this.showLoading(true);

        try {
            const submitData = {
                ...formData,
                createdBy: this.currentUser.UserID
            };

            const response = await fetch('../backend/api/enrollment.php?action=submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(submitData)
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Submission failed');
            }

            alert(`✓ Enrollment Submitted Successfully!\n\nStatus: Pending Approval`);
            this.handleReset(null);

        } catch (error) {
            console.error(error);
            alert('❌ Error submitting enrollment:\n\n' + error.message);
        } finally {
            this.showLoading(false);
            console.log('=== FORM SUBMISSION ENDED ===');
        }
    }

    handleReset(e) {
        if (e) e.preventDefault();
        
        if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
            // Reset all text inputs
            document.querySelectorAll('input[type="text"], input[type="number"], input[type="date"], input[type="tel"]').forEach(input => {
                input.value = '';
            });

            // Reset all selects
            document.querySelectorAll('select').forEach(select => {
                select.selectedIndex = 0;
            });

            // Uncheck all radios
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.checked = false;
            });

            // Clear enrollment history
            this.studentEnrollmentHistory = [];
            this.latestEnrollment = null;
            this.removeEnrollmentHistoryDisplay();

            // Remove any notifications
            const notification = document.getElementById('lrnNotification');
            if (notification) {
                notification.remove();
            }

            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            console.log('Form reset completed');
        }
    }

    showLoading(show) {
        if (!this.submitBtn) return;

        this.submitBtn.disabled = show;

        const span = this.submitBtn.querySelector('span');
        if (span) {
            span.textContent = show ? 'Submitting...' : 'Submit Enrollment';
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('DOM loaded, initializing enrollment form handler...');
        new EnrollmentFormHandler();
    });
} else {
    console.log('DOM already loaded, initializing enrollment form handler...');
    new EnrollmentFormHandler();
}