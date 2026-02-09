// =====================================================
// Revision Request Handler
// File: js/revision-request-handler.js
// Created: 2026-02-08
// =====================================================

class RevisionRequestHandler {
    constructor() {
        this.currentUser = null;
        this.currentStudent = null;
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
     * Show revision request form
     */
    showRevisionRequestForm(student) {
        this.currentStudent = student;

        const existingModal = document.getElementById('revisionRequestModal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'revisionRequestModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 overflow-y-auto';
        
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full my-8">
                <!-- Header -->
                <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">Request Student Data Revision</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                            Student: ${student.FullName || `${student.FirstName} ${student.LastName}`} (LRN: ${student.LRN})
                        </p>
                        <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
                            ⚠️ All revisions require Registrar or Principal approval
                        </p>
                    </div>
                    <button onclick="document.getElementById('revisionRequestModal').remove()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <span class="material-icons-outlined">close</span>
                    </button>
                </div>

                <!-- Content -->
                <div class="p-6 space-y-6">
                    <!-- Request Type -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Revision Type <span class="text-red-600">*</span>
                        </label>
                        <select id="revision-type" class="form-select w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary">
                            <option value="">Select revision type...</option>
                            <option value="Personal_Info">Personal Information</option>
                            <option value="Name_Correction">Name Correction</option>
                            <option value="Gender_Correction">Gender Correction</option>
                            <option value="Contact_Info">Contact Information</option>
                            <option value="Parent_Guardian_Info">Parent/Guardian Information</option>
                            <option value="Address">Address</option>
                            <option value="Enrollment_Info">Enrollment Information</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <!-- Priority -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Priority <span class="text-red-600">*</span>
                        </label>
                        <select id="revision-priority" class="form-select w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary">
                            <option value="Normal">Normal</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Use "Urgent" only for critical corrections (e.g., gender, legal name changes)
                        </p>
                    </div>

                    <!-- Fields to Change -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Fields to Change <span class="text-red-600">*</span>
                        </label>
                        <div id="fields-to-change-container" class="space-y-4">
                            <div class="field-change-item border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Field Name</label>
                                        <input type="text" class="field-name form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="e.g., FirstName, Gender, etc.">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Current Value</label>
                                        <input type="text" class="field-old form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="Current value">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">New Value</label>
                                        <input type="text" class="field-new form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="Correct value">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button id="btn-add-field" class="mt-3 text-sm text-primary hover:text-green-700 dark:text-green-400 dark:hover:text-green-300 flex items-center gap-1">
                            <span class="material-icons-outlined text-[18px]">add</span>
                            Add Another Field
                        </button>
                    </div>

                    <!-- Justification -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Justification/Reason <span class="text-red-600">*</span>
                        </label>
                        <textarea 
                            id="revision-justification"
                            rows="4" 
                            class="form-textarea w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary"
                            placeholder="Explain why this revision is needed. Be specific and provide context."
                        ></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Provide clear reasoning to help reviewers understand the necessity of this change
                        </p>
                    </div>

                    <!-- Supporting Documents -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Supporting Documents
                        </label>
                        <textarea 
                            id="revision-supporting-docs"
                            rows="2" 
                            class="form-textarea w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary"
                            placeholder="List any supporting documents available (e.g., PSA Birth Certificate, Court Order, etc.)"
                        ></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Note: Document uploads are not required, but please specify what documents can be provided for verification
                        </p>
                    </div>

                    <!-- Warning Box -->
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                        <div class="flex gap-3">
                            <span class="material-icons-outlined text-yellow-600 dark:text-yellow-400 flex-shrink-0">warning</span>
                            <div class="text-sm text-yellow-800 dark:text-yellow-300">
                                <p class="font-semibold mb-1">Important Notes:</p>
                                <ul class="list-disc list-inside space-y-1 text-xs">
                                    <li>All revision requests require approval from Registrar or Principal</li>
                                    <li>Changes will only be implemented after approval</li>
                                    <li>Provide accurate information and supporting documentation</li>
                                    <li>False information may result in disciplinary action</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button onclick="document.getElementById('revisionRequestModal').remove()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        Cancel
                    </button>
                    <button id="btn-submit-revision" class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                        <span class="material-icons-outlined text-[18px]">send</span>
                        Submit Request
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);

        // Bind events
        this.bindRevisionFormEvents();
    }

    bindRevisionFormEvents() {
        const addFieldBtn = document.getElementById('btn-add-field');
        const submitBtn = document.getElementById('btn-submit-revision');

        if (addFieldBtn) {
            addFieldBtn.addEventListener('click', () => this.addFieldRow());
        }

        if (submitBtn) {
            submitBtn.addEventListener('click', () => this.submitRevisionRequest());
        }
    }

    addFieldRow() {
        const container = document.getElementById('fields-to-change-container');
        const newRow = document.createElement('div');
        newRow.className = 'field-change-item border border-gray-200 dark:border-gray-700 rounded-lg p-4 relative';
        
        newRow.innerHTML = `
            <button class="btn-remove-field absolute top-2 right-2 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                <span class="material-icons-outlined text-[20px]">close</span>
            </button>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Field Name</label>
                    <input type="text" class="field-name form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="e.g., FirstName">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Current Value</label>
                    <input type="text" class="field-old form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="Current value">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">New Value</label>
                    <input type="text" class="field-new form-input w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="Correct value">
                </div>
            </div>
        `;
        
        container.appendChild(newRow);

        // Bind remove button
        const removeBtn = newRow.querySelector('.btn-remove-field');
        removeBtn.addEventListener('click', () => newRow.remove());
    }

    async submitRevisionRequest() {
        // Gather form data
        const revisionType = document.getElementById('revision-type').value;
        const priority = document.getElementById('revision-priority').value;
        const justification = document.getElementById('revision-justification').value;
        const supportingDocs = document.getElementById('revision-supporting-docs').value;

        // Validate
        if (!revisionType) {
            alert('Please select a revision type');
            return;
        }

        if (!justification.trim()) {
            alert('Please provide justification for this revision');
            return;
        }

        // Gather field changes
        const fieldRows = document.querySelectorAll('.field-change-item');
        const fieldsToChange = [];

        for (const row of fieldRows) {
            const fieldName = row.querySelector('.field-name').value.trim();
            const oldValue = row.querySelector('.field-old').value.trim();
            const newValue = row.querySelector('.field-new').value.trim();

            if (fieldName && oldValue && newValue) {
                fieldsToChange.push({
                    field: fieldName,
                    oldValue: oldValue,
                    newValue: newValue
                });
            }
        }

        if (fieldsToChange.length === 0) {
            alert('Please specify at least one field to change');
            return;
        }

        // Confirm submission
        if (!confirm(`Submit revision request for ${fieldsToChange.length} field(s)?`)) {
            return;
        }

        try {
            const response = await fetch('../backend/api/revision-requests.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    StudentID: this.currentStudent.StudentID,
                    EnrollmentID: this.currentStudent.EnrollmentID || null,
                    RequestedBy: this.currentUser.UserID,
                    RequestType: revisionType,
                    FieldsToChange: fieldsToChange,
                    Justification: justification,
                    SupportingDocuments: supportingDocs || null,
                    Priority: priority
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Revision request submitted successfully! Request ID: ' + result.requestId);
                document.getElementById('revisionRequestModal').remove();
            } else {
                alert('Error submitting request: ' + result.message);
            }

        } catch (error) {
            console.error('Error submitting revision request:', error);
            alert('Error submitting revision request');
        }
    }

    /**
     * Show approval interface for Registrar/Principal
     */
    async showApprovalInterface(requestId) {
        try {
            const response = await fetch(`../backend/api/revision-requests.php?action=get_details&request_id=${requestId}`);
            const result = await response.json();

            if (!result.success) {
                alert('Error loading request details: ' + result.message);
                return;
            }

            const request = result.data;
            this.renderApprovalModal(request);

        } catch (error) {
            console.error('Error loading request:', error);
            alert('Error loading request details');
        }
    }

    renderApprovalModal(request) {
        const existingModal = document.getElementById('approvalModal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'approvalModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 overflow-y-auto';
        
        const priorityColors = {
            'Urgent': 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            'High': 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
            'Normal': 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
            'Low': 'bg-gray-100 text-gray-800 dark:bg-gray-900/40 dark:text-gray-300'
        };

        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-5xl w-full my-8">
                <!-- Header -->
                <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-3">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Revision Request #${request.RequestID}</h3>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium ${priorityColors[request.Priority]}">
                                    ${request.Priority}
                                </span>
                            </div>
                            <div class="mt-2 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <p><strong>Student:</strong> ${request.StudentName} (LRN: ${request.LRN})</p>
                                <p><strong>Requested by:</strong> ${request.RequestedByName} (${request.RequesterRole})</p>
                                <p><strong>Request Date:</strong> ${new Date(request.CreatedAt).toLocaleString()}</p>
                                <p><strong>Type:</strong> ${request.RequestType.replace(/_/g, ' ')}</p>
                            </div>
                        </div>
                        <button onclick="document.getElementById('approvalModal').remove()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            <span class="material-icons-outlined">close</span>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="p-6 space-y-6 max-h-[60vh] overflow-y-auto">
                    <!-- Fields to Change -->
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Requested Changes</h4>
                        <div class="space-y-3">
                            ${request.FieldsToChange.map(change => `
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Field: <span class="text-primary">${change.field}</span>
                                    </p>
                                    <div class="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400 mb-1">Current Value:</p>
                                            <p class="font-medium text-red-600 dark:text-red-400">${change.oldValue}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400 mb-1">New Value:</p>
                                            <p class="font-medium text-green-600 dark:text-green-400">${change.newValue}</p>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>

                    <!-- Justification -->
                    <div>
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Justification</h4>
                        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300">
                            ${request.Justification}
                        </div>
                    </div>

                    <!-- Supporting Documents -->
                    ${request.SupportingDocuments ? `
                        <div>
                            <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Supporting Documents</h4>
                            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300">
                                ${request.SupportingDocuments}
                            </div>
                        </div>
                    ` : ''}

                    <!-- Review Notes -->
                    <div>
                        <label class="block font-semibold text-gray-900 dark:text-white mb-2">
                            Review Notes
                        </label>
                        <textarea 
                            id="review-notes"
                            rows="4" 
                            class="form-textarea w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-primary focus:ring-primary"
                            placeholder="Add notes about your decision..."
                        ></textarea>
                    </div>
                </div>

                <!-- Footer -->
                <div class="bg-gray-50 dark:bg-gray-900 px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                    <button onclick="document.getElementById('approvalModal').remove()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        Cancel
                    </button>
                    <button id="btn-reject-request" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2">
                        <span class="material-icons-outlined text-[18px]">cancel</span>
                        Reject
                    </button>
                    <button id="btn-approve-request" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                        <span class="material-icons-outlined text-[18px]">check_circle</span>
                        Approve
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);

        // Bind events
        const approveBtn = document.getElementById('btn-approve-request');
        const rejectBtn = document.getElementById('btn-reject-request');

        if (approveBtn) {
            approveBtn.addEventListener('click', () => this.approveRequest(request.RequestID));
        }

        if (rejectBtn) {
            rejectBtn.addEventListener('click', () => this.rejectRequest(request.RequestID));
        }
    }

    async approveRequest(requestId) {
        const reviewNotes = document.getElementById('review-notes').value;

        if (!confirm('Approve this revision request? Changes will be ready for implementation.')) {
            return;
        }

        try {
            const response = await fetch('../backend/api/revision-requests.php?action=approve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    RequestID: requestId,
                    ReviewedBy: this.currentUser.UserID,
                    ReviewNotes: reviewNotes || null
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Request approved successfully!');
                document.getElementById('approvalModal').remove();
                // Refresh list if available
                if (typeof this.refreshRequestList === 'function') {
                    this.refreshRequestList();
                }
            } else {
                alert('Error approving request: ' + result.message);
            }

        } catch (error) {
            console.error('Error approving request:', error);
            alert('Error approving request');
        }
    }

    async rejectRequest(requestId) {
        const reviewNotes = document.getElementById('review-notes').value;

        if (!reviewNotes.trim()) {
            alert('Please provide a reason for rejection');
            return;
        }

        if (!confirm('Reject this revision request? The requester will be notified.')) {
            return;
        }

        try {
            const response = await fetch('../backend/api/revision-requests.php?action=reject', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    RequestID: requestId,
                    ReviewedBy: this.currentUser.UserID,
                    ReviewNotes: reviewNotes
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Request rejected');
                document.getElementById('approvalModal').remove();
                // Refresh list if available
                if (typeof this.refreshRequestList === 'function') {
                    this.refreshRequestList();
                }
            } else {
                alert('Error rejecting request: ' + result.message);
            }

        } catch (error) {
            console.error('Error rejecting request:', error);
            alert('Error rejecting request');
        }
    }

    /**
     * Implement approved revision (apply changes to database)
     */
    async implementRevision(requestId) {
        if (!confirm('Implement this approved revision? Changes will be applied to the student record.')) {
            return;
        }

        try {
            const response = await fetch('../backend/api/revision-requests.php?action=implement', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    RequestID: requestId,
                    ImplementedBy: this.currentUser.UserID
                })
            });

            const result = await response.json();

            if (result.success) {
                alert('Revision implemented successfully! Student record has been updated.');
                // Refresh related views
                if (typeof window.studentManagement !== 'undefined') {
                    window.studentManagement.loadStudents();
                }
            } else {
                alert('Error implementing revision: ' + result.message);
            }

        } catch (error) {
            console.error('Error implementing revision:', error);
            alert('Error implementing revision');
        }
    }
}

// Export for use in other scripts
window.RevisionRequestHandler = RevisionRequestHandler;