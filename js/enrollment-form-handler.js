// =====================================================
// Enrollment Form Handler - Updated for New Schema
// File: js/enrollment-form-handler.js
// =====================================================

class EnrollmentFormHandler {
    constructor() {
        this.formData = {};
        this.currentUser = null;
        this.submitBtn = null;
        this.init();
    }

    init() {
        // Get current logged-in user
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
        // Find buttons using a more reliable method
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

        // Bind submit button
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

        // Bind reset button
        if (resetBtn) {
            resetBtn.addEventListener('click', (e) => {
                console.log('Reset button clicked!');
                this.handleReset(e);
            });
            console.log('Reset button event listener attached');
        } else {
            console.error('Reset button not found!');
        }

        // Grade level change
        const gradeLevelSelect = document.querySelectorAll('select')[0];
        if (gradeLevelSelect) {
            gradeLevelSelect.addEventListener('change', (e) => this.handleGradeLevelChange(e));
            console.log('Grade level change listener attached');
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
        const trackSection = Array.from(document.querySelectorAll('h3'))
            .find(h3 => h3.textContent.includes('Track'))?.closest('.space-y-4');
        
        if (trackSection) {
            if (gradeLevel === 'Grade 11' || gradeLevel === 'Grade 12') {
                trackSection.style.display = 'block';
            } else {
                trackSection.style.display = 'none';
                // Clear track selections
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

        // Enrollment Details
        formData.schoolYear = this.getInputByPlaceholder(inputs, '2024-2025')?.value.trim() || '';
        formData.gradeLevel = this.getGradeLevelID(document.querySelectorAll('select')[0]?.value);
        formData.lrn = this.getInputByPlaceholder(inputs, 'LRN')?.value.trim() || null;

        console.log('Basic enrollment info:', {
            schoolYear: formData.schoolYear,
            gradeLevel: formData.gradeLevel,
            lrn: formData.lrn
        });

        // Type of Learner
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

        console.log('Learner type:', formData.learnerType);

        // Track (for Grade 11 & 12)
        const trackRadio = document.querySelector('input[name="track"]:checked');
        if (trackRadio) {
            const trackLabel = trackRadio.nextSibling?.textContent.trim() || 
                             trackRadio.parentElement.textContent.trim();
            formData.strandID = this.mapTrackToStrandID(trackLabel);
        } else {
            formData.strandID = null;
        }

        // Learner's Information
        const nameInputs = Array.from(inputs);
        formData.lastName = this.getInputByPlaceholder(nameInputs, 'Dela Cruz')?.value.trim() || '';
        formData.firstName = this.getInputByPlaceholder(nameInputs, 'Juan')?.value.trim() || '';
        formData.middleName = this.getInputByPlaceholder(nameInputs, 'Santos')?.value.trim() || null;
        formData.extensionName = this.getInputByPlaceholder(nameInputs, 'Jr.')?.value.trim() || null;
        formData.birthdate = document.querySelector('input[type="date"]')?.value || '';
        formData.age = parseInt(this.getInputByPlaceholder(nameInputs, '16')?.value) || null;
        formData.sex = document.querySelectorAll('select')[1]?.value || 'Male';
        formData.religion = this.getInputByPlaceholder(nameInputs, 'Roman Catholic')?.value.trim() || null;

        console.log('Student name:', formData.firstName, formData.lastName);

        // IP Community
        const ipRadio = document.querySelector('input[name="ip-community"]:checked');
        formData.isIPCommunity = ipRadio?.nextSibling?.textContent.trim() === 'Yes';
        formData.ipCommunitySpecify = null; // Set manually if yes

        // Disability
        const disabilityRadio = document.querySelector('input[name="disability"]:checked');
        formData.isPWD = disabilityRadio?.nextSibling?.textContent.trim() === 'Yes';
        formData.pwdSpecify = this.getInputByPlaceholder(nameInputs, 'If yes, please specify')?.value.trim() || null;

        // Current Address
        formData.houseNumber = this.getInputByPlaceholder(nameInputs, '123')?.value.trim() || null;
        formData.sitioStreet = this.getInputByPlaceholder(nameInputs, 'Sampaguita St.')?.value.trim() || null;
        formData.barangay = this.getInputByPlaceholder(nameInputs, 'San Jose')?.value.trim() || '';
        formData.municipality = this.getInputByPlaceholder(nameInputs, 'Antipolo City')?.value.trim() || '';
        formData.province = this.getInputByPlaceholder(nameInputs, 'Rizal')?.value.trim() || '';

        // Parent/Guardian Information
        const parentSection = Array.from(document.querySelectorAll('.space-y-4'))
            .find(section => section.textContent.includes("Father's Name"));

        if (parentSection) {
            const parentInputs = parentSection.querySelectorAll('input');
            let idx = 0;
            
            // Father's Name
            formData.fatherLastName = parentInputs[idx++]?.value.trim() || null;
            formData.fatherFirstName = parentInputs[idx++]?.value.trim() || null;
            formData.fatherMiddleName = parentInputs[idx++]?.value.trim() || null;
            
            // Mother's Maiden Name
            formData.motherLastName = parentInputs[idx++]?.value.trim() || null;
            formData.motherFirstName = parentInputs[idx++]?.value.trim() || null;
            formData.motherMiddleName = parentInputs[idx++]?.value.trim() || null;
            
            // Legal Guardian
            formData.guardianLastName = parentInputs[idx++]?.value.trim() || null;
            formData.guardianFirstName = parentInputs[idx++]?.value.trim() || null;
            formData.guardianMiddleName = parentInputs[idx++]?.value.trim() || null;
            
            // Contact Number
            formData.contactNumber = parentInputs[idx]?.value.trim() || '';
        }

        console.log('Complete form data:', formData);
        // Weight & Height (if you add these fields to the form)
        formData.weight = this.getInputByPlaceholder(nameInputs, 'Weight (kg)')?.value || null;
        formData.height = this.getInputByPlaceholder(nameInputs, 'Height (m)')?.value || null;
        
        // 4Ps Beneficiary
        const fourPsRadio = document.querySelector('input[name="4ps-beneficiary"]:checked');
        formData.is4PsBeneficiary = fourPsRadio?.nextSibling?.textContent.trim() === 'Yes';
        
        // Zip Code & Country
        formData.zipCode = this.getInputByPlaceholder(nameInputs, 'Zip Code')?.value.trim() || null;
        formData.country = this.getInputByPlaceholder(nameInputs, 'Country')?.value.trim() || 'Philippines';
        
        // Enrollment Type (Regular/Late/Transferee)
        // Default to 'Regular' for now - you can add a radio button for this
        formData.enrollmentType = 'Regular';
        
        // Set encoded date
        formData.encodedDate = new Date().toISOString();

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
        // Clean the label
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
        // Clean the label
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
        if (!formData.schoolYear) errors.push('School Year is required');
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

        // At least one parent/guardian
        const hasParent = formData.fatherFirstName || formData.motherFirstName || formData.guardianFirstName;
        if (!hasParent) {
            errors.push('At least one parent or guardian name is required');
        }
        // NEW: Validate enrollment type
        if (!formData.enrollmentType) {
            errors.push('Enrollment Type is required');
        }

        // Validate that enrollmentType matches learnerType logic
        if (formData.learnerType === 'Irregular_Transferee' && formData.enrollmentType !== 'Transferee') {
            formData.enrollmentType = 'Transferee'; // Auto-correct
        }

        return errors;
    }

async handleSubmit(e) {
    e.preventDefault();
    console.log('=== FORM SUBMISSION STARTED ===');

    const formData = this.collectFormData();

    // 1️Validate first
    const errors = this.validateForm(formData);
    if (errors.length > 0) {
        alert('Please fix the following errors:\n\n' + errors.join('\n'));
        return;
    }

    // 2️IP community prompt
    if (formData.isIPCommunity) {
        formData.ipCommunitySpecify = prompt('Please specify IP Community:');
        if (!formData.ipCommunitySpecify) {
            alert('IP Community specification is required');
            return;
        }
    }

    // 3️Confirmation
    const gradeText = ['', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'][formData.gradeLevel];
    const confirmMsg = `Please confirm enrollment submission:\n\n` +
        `Name: ${formData.firstName} ${formData.lastName}\n` +
        `Grade Level: ${gradeText}\n` +
        `School Year: ${formData.schoolYear}\n\n` +
        `Submit this enrollment form?`;

    if (!confirm(confirmMsg)) {
        return;
    }

    // 4️ ONLY show loading when we are 100% submitting
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
        // ALWAYS reset loading
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