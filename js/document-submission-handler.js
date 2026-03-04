// =====================================================
// Document Submission Handler - REVISED FOR NORMALIZED DB
// File: js/document-submission-handler.js
// Updated: 2026-03-04
// Revised to work with normalized documentsubmission table
// =====================================================

class DocumentSubmissionHandler {
    constructor() {
        this.currentUser = null;
        this.currentEnrollmentId = null;
        this.documentData = null;
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
     * Show document checklist modal for a student enrollment
     */
    async showDocumentChecklist(enrollmentId, studentName) {
        this.currentEnrollmentId = enrollmentId;

        try {
            // Fetch document status
            const url = `../backend/api/document-submission.php?action=get_status&enrollment_id=${enrollmentId}`;
            
            const response = await fetch(url);
            const result = await response.json();

            if (result.success && result.data) {
                this.documentData = result.data;
                this.renderDocumentModal(studentName || result.data.studentName);
            } else {
                // Create new document submission records if they don't exist
                const createResponse = await fetch('../backend/api/document-submission.php?action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        EnrollmentID: enrollmentId
                    })
                });
                
                const createResult = await createResponse.json();
                if (createResult.success) {
                    // Retry loading the documents
                    return this.showDocumentChecklist(enrollmentId, studentName);
                } else {
                    alert('Error creating document records: ' + createResult.message);
                }
            }

        } catch (error) {
            console.error('Error loading document checklist:', error);
            alert('Error loading document checklist: ' + error.message);
        }
    }

    renderDocumentModal(studentName) {
        const existingModal = document.getElementById('documentChecklistModal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'documentChecklistModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4';
        
        const documents = this.documentData.documents || [];
        const isComplete = this.documentData.isComplete || false;
        
        // Organize documents by type for easier access
        const docMap = {};
        documents.forEach(doc => {
            docMap[doc.DocumentType] = doc;
        });
        
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
                                    ${this.getCompletionText()}
                                </p>
                            </div>
                            <div class="text-right">
                                ${isComplete ? `
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
                        
                        <div class="space-y-4">
                            ${this.renderDocumentItem(docMap['PSA_Birth_Cert'], 'PSA Birth Certificate', 'Original and photocopy from Philippine Statistics Authority', true)}
                            
                            ${this.renderDocumentItem(docMap['Local_Birth_Cert'], 'Secondary Birth Certificate', 'Local Civil Registrar, Baptismal Certificate, or Barangay Certification (If PSA unavailable)', false)}
                            
                            ${this.renderDocumentItem(docMap['Report_Card'], 'Report Card (SF9/Form 138)', 'For transferees and moving-up students', true)}
                            
                            ${this.renderDocumentItem(docMap['Form_137'], 'Form 137 (SF10 - Permanent Record)', 'Required for verifying student records from previous schools', true)}
                        </div>
                    </div>

                    <!-- Optional Documents -->
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-4">Additional Documents (Optional)</h4>
                        
                        <div class="space-y-4">
                            ${this.renderDocumentItem(docMap['Good_Moral'], 'Certificate of Good Moral Character', 'From previous school', false)}
                            
                            ${this.renderDocumentItem(docMap['Transfer_Cert'], 'Transfer Certificate', 'For transferees from other schools', false)}
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="sticky bottom-0 bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button onclick="document.getElementById('documentChecklistModal').remove()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);

        // Bind checkbox events
        this.bindDocumentEvents();
    }

    renderDocumentItem(doc, title, description, isRequired) {
        if (!doc) return '';
        
        const canEdit = this.canCheckDocuments();
        const isSubmitted = doc.IsSubmitted == 1;
        const isVerified = doc.IsVerified == 1;
        
        return `
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <div class="flex items-start gap-4">
                    <input 
                        type="checkbox" 
                        id="doc-${doc.SubmissionID}"
                        data-submission-id="${doc.SubmissionID}"
                        ${isSubmitted ? 'checked' : ''}
                        ${canEdit ? '' : 'disabled'}
                        class="form-checkbox h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700 mt-1 document-checkbox"
                    />
                    <div class="flex-1">
                        <label for="doc-${doc.SubmissionID}" class="block font-medium text-gray-900 dark:text-white cursor-pointer">
                            ${title}
                            ${isRequired ? '<span class="ml-2 text-xs text-red-600 dark:text-red-400">(Required)</span>' : ''}
                        </label>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                            ${description}
                        </p>
                        ${isSubmitted ? `
                            <p class="text-xs text-green-600 dark:text-green-400 mt-2">
                                ✓ Submitted and verified
                            </p>
                        ` : ''}
                        ${doc.Notes ? `
                            <div class="mt-2">
                                <p class="text-xs text-gray-500 dark:text-gray-400 italic">${doc.Notes}</p>
                            </div>
                        ` : ''}
                        ${canEdit ? `
                            <div class="mt-2">
                                <input 
                                    type="text" 
                                    id="doc-notes-${doc.SubmissionID}"
                                    data-submission-id="${doc.SubmissionID}"
                                    placeholder="Add notes..."
                                    value="${doc.Notes || ''}"
                                    class="form-input text-xs w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white document-notes"
                                />
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    bindDocumentEvents() {
        // Bind checkbox change events
        const checkboxes = document.querySelectorAll('.document-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                this.updateDocument(e.target);
            });
        });

        // Bind notes blur events (save when user leaves field)
        const notesInputs = document.querySelectorAll('.document-notes');
        notesInputs.forEach(input => {
            input.addEventListener('blur', (e) => {
                this.updateDocumentNotes(e.target);
            });
        });
    }

    async updateDocument(checkbox) {
        const submissionId = checkbox.dataset.submissionId;
        const value = checkbox.checked ? 1 : 0;

        try {
            const payload = {
                SubmissionID: parseInt(submissionId),
                IsSubmitted: value,
                IsVerified: value  // When checked, mark as both submitted AND verified
            };

            const response = await fetch('../backend/api/document-submission.php?action=update_document', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (!result.success) {
                alert('Error updating document: ' + result.message);
                // Revert checkbox
                checkbox.checked = !checkbox.checked;
            } else {
                console.log('Document updated successfully');
                // Optionally refresh the modal to show updated completion status
                // this.showDocumentChecklist(this.currentEnrollmentId);
            }

        } catch (error) {
            console.error('Error updating document:', error);
            alert('Error updating document: ' + error.message);
            checkbox.checked = !checkbox.checked;
        }
    }

    async updateDocumentNotes(input) {
        const submissionId = input.dataset.submissionId;
        const notes = input.value.trim();

        try {
            const payload = {
                SubmissionID: parseInt(submissionId),
                Notes: notes || null
            };

            const response = await fetch('../backend/api/document-submission.php?action=update_document', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (!result.success) {
                console.error('Error updating notes:', result.message);
            } else {
                console.log('Notes updated successfully');
            }

        } catch (error) {
            console.error('Error updating notes:', error);
        }
    }

    getCompletionText() {
        if (!this.documentData) return 'No documents submitted yet';
        
        const hasBirthCert = this.documentData.hasBirthCert;
        const hasReportCard = this.documentData.hasReportCard;
        const hasForm137 = this.documentData.hasForm137;
        
        const requiredCount = (hasBirthCert ? 1 : 0) + (hasReportCard ? 1 : 0) + (hasForm137 ? 1 : 0);
        
        if (requiredCount === 3) {
            return 'All required documents submitted ✓';
        } else {
            return `${requiredCount} of 3 required documents submitted`;
        }
    }

    canCheckDocuments() {
        // Adviser, Registrar, and ICT Coordinator can check/submit documents
        return ['Adviser', 'Registrar', 'ICT_Coordinator'].includes(this.currentUser.Role);
    }
}

// Export for use in other scripts
window.DocumentSubmissionHandler = DocumentSubmissionHandler;