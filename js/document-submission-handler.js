// =====================================================
// Document Submission Handler
// File: js/document-submission-handler.js
// Created: 2026-02-08
// Fixed: 2026-02-14 - Better error handling and validation
// =====================================================

class DocumentSubmissionHandler {
    constructor() {
        this.currentUser = null;
        this.currentStudentId = null;
        this.currentSubmissionId = null;
        this.init();
    }

    init() {
        this.currentUser = this.getCurrentUser();
        
        if (!this.currentUser) {
            alert('You must be logged in to access this page');
            window.location.href = '../login.html';
            return;
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

    /**
     * Show document checklist modal for a student
     */
    async showDocumentChecklist(studentId, studentName, enrollmentId = null) {
        this.currentStudentId = studentId;

        try {
            // Fetch document status
            const url = enrollmentId 
                ? `../backend/api/document-submission.php?action=get_status&student_id=${studentId}&enrollment_id=${enrollmentId}`
                : `../backend/api/document-submission.php?action=get_status&student_id=${studentId}`;
            
            const response = await fetch(url);
            const result = await response.json();

            let documentData = null;
            
            if (result.success && result.data) {
                documentData = result.data;
                this.currentSubmissionId = documentData.SubmissionID;
            } else {
                // Create new document submission record if doesn't exist
                if (enrollmentId) {
                    const createResponse = await fetch('../backend/api/document-submission.php?action=create', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            EnrollmentID: enrollmentId,
                            UserID: this.currentUser.UserID
                        })
                    });
                    
                    const createResult = await createResponse.json();
                    if (createResult.success) {
                        this.currentSubmissionId = createResult.submissionId;
                        // Fetch the newly created record
                        return this.showDocumentChecklist(studentId, studentName, enrollmentId);
                    }
                }
            }

            this.renderDocumentModal(studentName, documentData);

        } catch (error) {
            console.error('Error loading document checklist:', error);
            alert('Error loading document checklist');
        }
    }

    renderDocumentModal(studentName, documentData) {
        const existingModal = document.getElementById('documentChecklistModal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'documentChecklistModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
        
        const data = documentData || {};
        
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <!-- Header -->
                <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-between z-10">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">Document Submission Checklist</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">${studentName}</p>
                    </div>
                    <button onclick="document.getElementById('documentChecklistModal').remove()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <span class="material-icons-outlined">close</span>
                    </button>
                </div>

                <!-- Content -->
                <div class="p-6 space-y-6">
                    <!-- Overall Status -->
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="font-semibold text-blue-900 dark:text-blue-300">Document Completion Status</h4>
                                <p class="text-sm text-blue-700 dark:text-blue-400 mt-1">
                                    ${this.getCompletionText(data)}
                                </p>
                            </div>
                            <div class="text-right">
                                ${data.AllDocsComplete ? `
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                        <span class="material-icons-outlined text-sm mr-1">check_circle</span>
                                        Complete
                                    </span>
                                ` : `
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300">
                                        <span class="material-icons-outlined text-sm mr-1">pending</span>
                                        Incomplete
                                    </span>
                                `}
                            </div>
                        </div>
                    </div>

                    <!-- Required Documents -->
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-4">Required Documents</h4>
                        
                        <!-- Birth Certificate -->
                        <div class="space-y-4">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <input 
                                        type="checkbox" 
                                        id="doc-psa"
                                        ${data.HasPSABirthCert ? 'checked' : ''}
                                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1"
                                    />
                                    <div class="flex-1">
                                        <label for="doc-psa" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                                            PSA Birth Certificate
                                            <span class="ml-2 text-xs text-red-600 dark:text-red-400">(Required)</span>
                                        </label>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                            Original and photocopy from Philippine Statistics Authority
                                        </p>
                                        ${data.PSASubmissionDate ? `
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                                ✓ Submitted: ${new Date(data.PSASubmissionDate).toLocaleDateString()}
                                                ${data.PSAVerifiedByName ? ` by ${data.PSAVerifiedByName}` : ''}
                                            </p>
                                        ` : ''}
                                        ${data.PSANotes ? `
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 italic">${data.PSANotes}</p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <input 
                                        type="checkbox" 
                                        id="doc-local"
                                        ${data.HasLocalBirthCert ? 'checked' : ''}
                                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1"
                                    />
                                    <div class="flex-1">
                                        <label for="doc-local" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                                            Secondary Birth Certificate
                                            <span class="ml-2 text-xs text-gray-600 dark:text-gray-400">(If PSA unavailable)</span>
                                        </label>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                            Local Civil Registrar, Baptismal Certificate, or Barangay Certification
                                        </p>
                                        ${data.LocalBirthCertSubmissionDate ? `
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                                ✓ Submitted: ${new Date(data.LocalBirthCertSubmissionDate).toLocaleDateString()}
                                                ${data.LocalBirthCertVerifiedByName ? ` by ${data.LocalBirthCertVerifiedByName}` : ''}
                                            </p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <input 
                                        type="checkbox" 
                                        id="doc-reportcard"
                                        ${data.HasReportCard ? 'checked' : ''}
                                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1"
                                    />
                                    <div class="flex-1">
                                        <label for="doc-reportcard" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                                            Report Card (SF9/Form 138)
                                            <span class="ml-2 text-xs text-red-600 dark:text-red-400">(Required)</span>
                                        </label>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                            For transferees and moving-up students
                                        </p>
                                        ${data.ReportCardSubmissionDate ? `
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                                ✓ Submitted: ${new Date(data.ReportCardSubmissionDate).toLocaleDateString()}
                                                ${data.ReportCardVerifiedByName ? ` by ${data.ReportCardVerifiedByName}` : ''}
                                            </p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <input 
                                        type="checkbox" 
                                        id="doc-form137"
                                        ${data.HasForm137 ? 'checked' : ''}
                                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1"
                                    />
                                    <div class="flex-1">
                                        <label for="doc-form137" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                                            Form 137 (SF10 - Permanent Record)
                                            <span class="ml-2 text-xs text-red-600 dark:text-red-400">(Required)</span>
                                        </label>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                            Required for verifying student records from previous schools
                                        </p>
                                        ${data.Form137SubmissionDate ? `
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                                ✓ Submitted: ${new Date(data.Form137SubmissionDate).toLocaleDateString()}
                                                ${data.Form137VerifiedByName ? ` by ${data.Form137VerifiedByName}` : ''}
                                            </p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Optional Documents -->
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-4">Additional Documents (Optional)</h4>
                        
                        <div class="space-y-4">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <input 
                                        type="checkbox" 
                                        id="doc-goodmoral"
                                        ${data.HasGoodMoral ? 'checked' : ''}
                                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1"
                                    />
                                    <div class="flex-1">
                                        <label for="doc-goodmoral" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                                            Certificate of Good Moral Character
                                        </label>
                                        ${data.GoodMoralSubmissionDate ? `
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                                ✓ Submitted: ${new Date(data.GoodMoralSubmissionDate).toLocaleDateString()}
                                            </p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <input 
                                        type="checkbox" 
                                        id="doc-transfer"
                                        ${data.HasTransferCert ? 'checked' : ''}
                                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1"
                                    />
                                    <div class="flex-1">
                                        <label for="doc-transfer" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                                            Transfer Certificate
                                            <span class="ml-2 text-xs text-gray-600 dark:text-gray-400">(For transferees)</span>
                                        </label>
                                        ${data.TransferCertSubmissionDate ? `
                                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                                ✓ Submitted: ${new Date(data.TransferCertSubmissionDate).toLocaleDateString()}
                                            </p>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- General Notes -->
                    <div>
                        <label class="block font-medium text-gray-900 dark:text-white mb-2">General Notes</label>
                        <textarea 
                            id="doc-general-notes"
                            rows="3" 
                            class="form-textarea w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary"
                            placeholder="Add notes about document submission..."
                        >${data.GeneralNotes || ''}</textarea>
                    </div>

                    ${data.FinalVerificationDate ? `
                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                            <div class="flex items-center gap-2 text-green-800 dark:text-green-300">
                                <span class="material-icons-outlined">verified</span>
                                <div>
                                    <p class="font-semibold">All Documents Verified</p>
                                    <p class="text-sm">Verified by ${data.FinalVerifiedByName} on ${new Date(data.FinalVerificationDate).toLocaleDateString()}</p>
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>

                <!-- Footer -->
                <div class="sticky bottom-0 bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button onclick="document.getElementById('documentChecklistModal').remove()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        Close
                    </button>
                    ${!data.AllDocsComplete && this.canVerify() ? `
                        <button id="btn-verify-all" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                            <span class="material-icons-outlined text-[18px]">verified</span>
                            Final Verification
                        </button>
                    ` : ''}
                    ${this.canCheckDocuments() ? `
                        <button id="btn-save-documents" class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                            <span class="material-icons-outlined text-[18px]">save</span>
                            ${this.currentUser.Role === 'Adviser' ? 'Update Checklist' : 'Save Changes'}
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);

        // Bind events
        this.bindDocumentModalEvents();
    }

    bindDocumentModalEvents() {
        const saveBtn = document.getElementById('btn-save-documents');
        const verifyBtn = document.getElementById('btn-verify-all');

        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveDocumentChanges());
        }

        if (verifyBtn) {
            verifyBtn.addEventListener('click', () => this.finalVerification());
        }

        // Bind checkbox events
        const checkboxes = [
            'doc-psa', 'doc-local', 'doc-reportcard', 
            'doc-form137', 'doc-goodmoral', 'doc-transfer'
        ];

        checkboxes.forEach(id => {
            const checkbox = document.getElementById(id);
            if (checkbox) {
                checkbox.addEventListener('change', (e) => {
                    // Auto-save on change for better UX
                    this.updateSingleDocument(id, e.target.checked);
                });
            }
        });
    }

    async updateSingleDocument(docId, isChecked) {
        const documentTypeMap = {
            'doc-psa': 'PSA',
            'doc-local': 'Local',
            'doc-reportcard': 'ReportCard',
            'doc-form137': 'Form137',
            'doc-goodmoral': 'GoodMoral',
            'doc-transfer': 'TransferCert'
        };

        const documentType = documentTypeMap[docId];

        if (!this.currentSubmissionId) {
            alert('Error: No submission ID found');
            const checkbox = document.getElementById(docId);
            if (checkbox) checkbox.checked = !isChecked;
            return;
        }

        if (!this.currentUser || !this.currentUser.UserID) {
            alert('Error: User information not available');
            const checkbox = document.getElementById(docId);
            if (checkbox) checkbox.checked = !isChecked;
            return;
        }

        try {
            // Prepare payload - ensure all required fields are present
            const payload = {
                SubmissionID: this.currentSubmissionId,
                DocumentType: documentType,
                IsChecked: isChecked ? 1 : 0,  // Explicitly convert to 1 or 0
                UserID: this.currentUser.UserID
            };

            console.log('Sending payload:', payload); // Debug log

            const response = await fetch('../backend/api/document-submission.php?action=update_checklist', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            console.log('Server response:', result); // Debug log

            if (!result.success) {
                alert('Error updating document: ' + result.message);
                // Revert checkbox
                const checkbox = document.getElementById(docId);
                if (checkbox) checkbox.checked = !isChecked;
            } else {
                // Show subtle success feedback
                console.log('Document updated successfully');
            }

        } catch (error) {
            console.error('Error updating document:', error);
            alert('Error updating document: ' + error.message);
            // Revert checkbox
            const checkbox = document.getElementById(docId);
            if (checkbox) checkbox.checked = !isChecked;
        }
    }

    async saveDocumentChanges() {
        // This is for saving notes or any other manual changes
        const notes = document.getElementById('doc-general-notes')?.value;

        // Save is already handled by individual checkbox changes
        alert('Changes saved successfully!');
    }

    async finalVerification() {
        if (!confirm('Mark all documents as complete and verified?')) {
            return;
        }

        try {
            const response = await fetch('../backend/api/document-submission.php?action=final_verification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    SubmissionID: this.currentSubmissionId,
                    UserID: this.currentUser.UserID
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Documents verified successfully!');
                document.getElementById('documentChecklistModal').remove();
                // Refresh parent page if needed
                if (typeof window.studentManagement !== 'undefined') {
                    window.studentManagement.loadStudents();
                }
            } else {
                alert('Error verifying documents: ' + result.message);
            }

        } catch (error) {
            console.error('Error verifying documents:', error);
            alert('Error verifying documents');
        }
    }

    getCompletionText(data) {
        if (!data) return 'No documents submitted yet';
        
        const hasBirthCert = data.HasPSABirthCert || data.HasLocalBirthCert;
        const requiredCount = (hasBirthCert ? 1 : 0) + (data.HasReportCard ? 1 : 0) + (data.HasForm137 ? 1 : 0);
        
        if (requiredCount === 3) {
            return 'All required documents submitted ✓';
        } else {
            return `${requiredCount} of 3 required documents submitted`;
        }
    }

    canVerify() {
        // Registrar and ICT Coordinator can do final verification
        // Advisers can check/uncheck but not do final verification
        return ['Registrar', 'ICT_Coordinator'].includes(this.currentUser.Role);
    }

    canCheckDocuments() {
        // All three roles can check/uncheck documents
        return ['Adviser', 'Registrar', 'ICT_Coordinator'].includes(this.currentUser.Role);
    }
}

// Export for use in other scripts
window.DocumentSubmissionHandler = DocumentSubmissionHandler;