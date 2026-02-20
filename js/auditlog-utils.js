// auditlog-utils.js
// Shared audit log utilities for ICT Coordinator and Registrar roles

// Role-based configuration
const ROLE_FILTER_CONFIG = {
    'ICT_Coordinator': {
        allowedTables: ['user', 'employee', 'student', 'enrollment', 'section', 
                       'sectionassignment', 'StudentRevisionRequest', 'strand', 
                       'documentsubmission'],
        tableOptions: [
            { value: '', label: 'All Tables' },
            { value: 'user', label: 'User Accounts' },
            { value: 'employee', label: 'Employees' },
            { value: 'student', label: 'Students' },
            { value: 'enrollment', label: 'Enrollments' },
            { value: 'section', label: 'Sections' },
            { value: 'sectionassignment', label: 'Section Assignments' },
            { value: 'StudentRevisionRequest', label: 'Revision Requests' },
            { value: 'strand', label: 'Strands' },
            { value: 'documentsubmission', label: 'Document Submissions' }
        ],
        canViewAllUsers: true,
        canExportAll: true
    },
    'Registrar': {
        allowedTables: ['student', 'enrollment', 'section', 'sectionassignment', 
                       'StudentRevisionRequest', 'documentsubmission'],
        tableOptions: [
            { value: '', label: 'All Tables' },
            { value: 'student', label: 'Students' },
            { value: 'enrollment', label: 'Enrollments' },
            { value: 'section', label: 'Sections' },
            { value: 'sectionassignment', label: 'Section Assignments' },
            { value: 'StudentRevisionRequest', label: 'Revision Requests' },
            { value: 'documentsubmission', label: 'Document Submissions' }
        ],
        canViewAllUsers: false,
        canExportAll: true
    }
};

// Action color mapping
const ACTION_COLORS = {
    'INSERT': 'bg-green-100 text-green-800',
    'UPDATE': 'bg-blue-100 text-blue-800',
    'DELETE': 'bg-red-100 text-red-800',
    'STATUS_CHANGE': 'bg-yellow-100 text-yellow-800',
    'PASSWORD_RESET': 'bg-purple-100 text-purple-800',
    // ── Revision request lifecycle ──────────────────────────────────────────
    // Adviser submits the Edit Information form → creates a revision request
    'REVISION_REQUEST': 'bg-indigo-100 text-indigo-800',
    // Registrar/ICT approves the revision request
    'REVISION_APPROVED': 'bg-green-100 text-green-800',
    // Registrar/ICT rejects the revision request
    'REVISION_REJECTED': 'bg-red-100 text-red-800',
    // Changes are actually applied to the student record after approval
    'REVISION_IMPLEMENTED': 'bg-blue-100 text-blue-800',
    // ── Document lifecycle ──────────────────────────────────────────────────
    'DOCUMENT_SUBMISSION': 'bg-cyan-100 text-cyan-800',
    'DOCUMENT_VERIFICATION': 'bg-teal-100 text-teal-800'
};

// Action label mapping — human-readable strings shown in the audit log table
const ACTION_LABELS = {
    'INSERT': 'Created',
    'UPDATE': 'Updated',
    'DELETE': 'Deleted',
    'STATUS_CHANGE': 'Status Changed',
    'PASSWORD_RESET': 'Password Reset',
    // Adviser used "Edit Information" → submitted for approval
    'REVISION_REQUEST': 'Edit Submitted (Pending Approval)',
    // Registrar/ICT approved the pending edit
    'REVISION_APPROVED': 'Edit Approved',
    // Registrar/ICT rejected the pending edit
    'REVISION_REJECTED': 'Edit Rejected',
    // Approved changes were applied to the live student record
    'REVISION_IMPLEMENTED': 'Edit Applied to Record',
    'DOCUMENT_SUBMISSION': 'Document Submitted',
    'DOCUMENT_VERIFICATION': 'Document Verified'
};

// Table label mapping
const TABLE_LABELS = {
    'user': 'User Account',
    'employee': 'Employee',
    'student': 'Student',
    'enrollment': 'Enrollment',
    'section': 'Section',
    'sectionassignment': 'Section Assignment',
    // Revision requests are stored here; actions on this table use the
    // REVISION_REQUEST / REVISION_APPROVED / REVISION_REJECTED / REVISION_IMPLEMENTED
    // action types so they display correctly in the log.
    'StudentRevisionRequest': 'Student Edit Request',
    'strand': 'Strand',
    'documentsubmission': 'Document Submission'
};

// Global state
let allLogs = [];
let currentUser = null;
let currentPage = 0;
let pageSize = 50;
let totalRecords = 0;
let currentFilters = {};
let userRole = null;

/**
 * Initialize audit log functionality
 * @param {string} role - User's role (ICT_Coordinator or Registrar)
 * @param {Object} user - Current user object
 */
function initializeAuditLog(role, user) {
    userRole = role;
    currentUser = user;
    
    populateTableFilter();
    loadStats();
    loadLogs();
}

/**
 * Populate the table filter dropdown based on user role
 */
function populateTableFilter() {
    const filterTable = document.getElementById('filterTable');
    if (!filterTable) return;
    
    const config = ROLE_FILTER_CONFIG[userRole];
    if (!config) return;
    
    filterTable.innerHTML = config.tableOptions.map(opt => 
        `<option value="${opt.value}">${opt.label}</option>`
    ).join('');
}

/**
 * Load audit statistics
 */
async function loadStats() {
    try {
        let url = '../backend/api/auditlog.php?action=stats';
        
        if (userRole === 'Registrar') {
            url += '&user_role=Registrar';
        }
        
        const response = await fetch(url);
        const result = await response.json();
        
        if (result.success) {
            const stats = result.data.overall;
            document.getElementById('totalLogs').textContent = stats.total_logs || 0;
            document.getElementById('todayLogs').textContent = stats.today_logs || 0;
            document.getElementById('weekLogs').textContent = stats.week_logs || 0;
            document.getElementById('monthLogs').textContent = stats.month_logs || 0;
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

/**
 * Load audit logs with current filters
 */
async function loadLogs() {
    try {
        let url = `../backend/api/auditlog.php?action=all&limit=${pageSize}&offset=${currentPage * pageSize}`;
        
        if (userRole === 'Registrar') {
            url += '&user_role=Registrar';
        }
        
        if (currentFilters.table) url += `&table=${currentFilters.table}`;
        if (currentFilters.action) url += `&action_type=${currentFilters.action}`;
        if (currentFilters.startDate) url += `&start_date=${currentFilters.startDate}`;
        if (currentFilters.endDate) url += `&end_date=${currentFilters.endDate}`;
        
        const response = await fetch(url);
        const result = await response.json();
        
        if (result.success) {
            allLogs = result.data;
            totalRecords = result.total;
            displayLogs(allLogs);
            updatePagination();
        }
    } catch (error) {
        console.error('Error loading logs:', error);
        document.getElementById('auditLogTableBody').innerHTML = 
            '<tr><td colspan="7" class="px-6 py-8 text-center text-red-500">Failed to load audit logs</td></tr>';
    }
}

/**
 * Display logs in the table
 * @param {Array} logs - Array of log entries
 */
function displayLogs(logs) {
    const tbody = document.getElementById('auditLogTableBody');
    
    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">No audit logs found</td></tr>';
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const timestamp = new Date(log.ChangedAt).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

        // Resolve human-readable label; fall back to raw action string
        const actionLabel = ACTION_LABELS[log.Action] || log.Action;
        const actionColor = ACTION_COLORS[log.Action] || 'bg-gray-100 text-gray-800';
        // Resolve table display name
        const tableLabel = TABLE_LABELS[log.TableName] || log.TableName;
        
        return `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">${timestamp}</td>
                <td class="px-6 py-4">
                    <div class="text-sm font-medium">${log.ChangedByName || 'System'}</div>
                    <div class="text-xs text-gray-500">${log.ChangedByRole || 'N/A'}</div>
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full ${actionColor}">
                        ${actionLabel}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm">${tableLabel}</td>
                <td class="px-6 py-4 text-sm">${log.ActionDescription || 'No description'}</td>
                <td class="px-6 py-4 text-sm">${log.AffectedUserName || '-'}</td>
                <td class="px-6 py-4 text-right">
                    <button onclick="viewDetails(${log.LogID})" class="text-primary hover:text-primary/80" title="View Details">
                        <span class="material-symbols-outlined text-[20px]">visibility</span>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Update pagination controls
 */
function updatePagination() {
    const from = currentPage * pageSize + 1;
    const to = Math.min((currentPage + 1) * pageSize, totalRecords);
    
    document.getElementById('showingFrom').textContent = from;
    document.getElementById('showingTo').textContent = to;
    document.getElementById('totalRecords').textContent = totalRecords;
    
    document.getElementById('prevBtn').disabled = currentPage === 0;
    document.getElementById('nextBtn').disabled = (currentPage + 1) * pageSize >= totalRecords;
}

function previousPage() {
    if (currentPage > 0) {
        currentPage--;
        loadLogs();
    }
}

function nextPage() {
    if ((currentPage + 1) * pageSize < totalRecords) {
        currentPage++;
        loadLogs();
    }
}

function applyFilters() {
    currentFilters = {
        table: document.getElementById('filterTable').value,
        action: document.getElementById('filterAction').value,
        startDate: document.getElementById('filterStartDate').value,
        endDate: document.getElementById('filterEndDate').value
    };
    currentPage = 0;
    loadLogs();
}

function clearFilters() {
    document.getElementById('filterTable').value = '';
    document.getElementById('filterAction').value = '';
    document.getElementById('filterStartDate').value = '';
    document.getElementById('filterEndDate').value = '';
    currentFilters = {};
    currentPage = 0;
    loadLogs();
}

async function refreshLogs() {
    await loadStats();
    await loadLogs();
}

/**
 * View detailed information for a log entry
 */
function viewDetails(logId) {
    const log = allLogs.find(l => l.LogID === logId);
    if (!log) return;
    
    let oldValue = 'N/A';
    let newValue = 'N/A';
    
    try {
        if (log.OldValue) {
            const parsed = JSON.parse(log.OldValue);
            oldValue = `<pre class="bg-gray-100 dark:bg-gray-800 p-2 rounded text-xs overflow-x-auto">${JSON.stringify(parsed, null, 2)}</pre>`;
        }
    } catch (e) {
        oldValue = log.OldValue || 'N/A';
    }
    
    try {
        if (log.NewValue) {
            const parsed = JSON.parse(log.NewValue);
            newValue = `<pre class="bg-gray-100 dark:bg-gray-800 p-2 rounded text-xs overflow-x-auto">${JSON.stringify(parsed, null, 2)}</pre>`;
        }
    } catch (e) {
        newValue = log.NewValue || 'N/A';
    }

    // For revision requests, the NewValue contains the ChangedFields array.
    // Render it as a readable diff table when available.
    let diffTable = '';
    try {
        if (log.NewValue) {
            const parsed = JSON.parse(log.NewValue);
            const fields = parsed.ChangedFields || (Array.isArray(parsed) ? parsed : null);
            if (fields && fields.length > 0) {
                diffTable = `
                    <div class="mt-4">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Field Changes</p>
                        <table class="w-full text-xs border border-gray-200 dark:border-gray-700 rounded">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-3 py-2 text-left text-gray-600 dark:text-gray-400">Field</th>
                                    <th class="px-3 py-2 text-left text-red-600 dark:text-red-400">Old Value</th>
                                    <th class="px-3 py-2 text-left text-green-600 dark:text-green-400">New Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${fields.map(f => `
                                    <tr class="border-t border-gray-100 dark:border-gray-700">
                                        <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">${f.field}</td>
                                        <td class="px-3 py-2 text-red-600 dark:text-red-400">${f.oldValue || '—'}</td>
                                        <td class="px-3 py-2 text-green-600 dark:text-green-400">${f.newValue || '—'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
        }
    } catch (e) { /* ignore */ }
    
    const detailContent = `
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Log ID</p>
                <p class="text-sm dark:text-gray-400">${log.LogID}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Timestamp</p>
                <p class="text-sm dark:text-gray-400">${new Date(log.ChangedAt).toLocaleString()}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Changed By</p>
                <p class="text-sm dark:text-gray-400">${log.ChangedByName || 'System'} (${log.ChangedByRole || 'N/A'})</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">IP Address</p>
                <p class="text-sm dark:text-gray-400">${log.IPAddress || 'N/A'}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Table/Module</p>
                <p class="text-sm dark:text-gray-400">${TABLE_LABELS[log.TableName] || log.TableName}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Record ID</p>
                <p class="text-sm dark:text-gray-400">${log.RecordID}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Action</p>
                <p class="text-sm dark:text-gray-400">${ACTION_LABELS[log.Action] || log.Action}</p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Affected User</p>
                <p class="text-sm dark:text-gray-400">${log.AffectedUserName || 'N/A'}</p>
            </div>
        </div>
        <div class="mt-4">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</p>
            <p class="text-sm dark:text-gray-400">${log.ActionDescription || 'No description available'}</p>
        </div>
        ${diffTable || `
            <div class="mt-4">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Old Value</p>
                ${oldValue}
            </div>
            <div class="mt-4">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">New Value</p>
                ${newValue}
            </div>
        `}
    `;
    
    document.getElementById('detailContent').innerHTML = detailContent;
    document.getElementById('detailModal').classList.remove('hidden');
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.add('hidden');
}

/**
 * Export audit logs to CSV
 */
async function exportAuditLog() {
    try {
        let url = `../backend/api/auditlog.php?action=all&limit=10000&offset=0`;
        
        if (userRole === 'Registrar') {
            url += '&user_role=Registrar';
        }
        
        if (currentFilters.table) url += `&table=${currentFilters.table}`;
        if (currentFilters.action) url += `&action_type=${currentFilters.action}`;
        if (currentFilters.startDate) url += `&start_date=${currentFilters.startDate}`;
        if (currentFilters.endDate) url += `&end_date=${currentFilters.endDate}`;
        
        const response = await fetch(url);
        const result = await response.json();
        
        if (result.success && result.data.length > 0) {
            const headers = ['Timestamp', 'User', 'Role', 'Action', 'Table', 'Record ID', 'Description', 'Affected User', 'IP Address'];
            const csvContent = [
                headers.join(','),
                ...result.data.map(log => [
                    `"${new Date(log.ChangedAt).toLocaleString()}"`,
                    `"${log.ChangedByName || 'System'}"`,
                    `"${log.ChangedByRole || 'N/A'}"`,
                    // Use human-readable label in the CSV export too
                    `"${ACTION_LABELS[log.Action] || log.Action}"`,
                    `"${TABLE_LABELS[log.TableName] || log.TableName}"`,
                    log.RecordID,
                    `"${(log.ActionDescription || '').replace(/"/g, '""')}"`,
                    `"${log.AffectedUserName || '-'}"`,
                    `"${log.IPAddress || 'N/A'}"`
                ].join(','))
            ].join('\n');
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `audit_log_${userRole}_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
        } else {
            alert('No logs to export');
        }
    } catch (error) {
        console.error('Error exporting logs:', error);
        alert('Failed to export audit logs');
    }
}

function getInitials(firstName, lastName) {
    return (firstName?.charAt(0) || '') + (lastName?.charAt(0) || '');
}