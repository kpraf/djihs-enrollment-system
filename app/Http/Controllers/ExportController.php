<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Exports\EncodingTemplate;
use App\Services\Privacy\AccessLog;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ExportController extends Controller
{
    public function auditLog(Request $request, AuditTrail $trail): StreamedResponse
    {
        $logs = AuditLog::with('user:id,first_name,last_name')
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('id')->limit(20000)->get();
        AccessLog::exported($request, AccessLog::AUDIT_LOG, 'Audit log (CSV)');

        return TableExport::csv('Audit_Log_'.now()->format('Y-m-d').'.csv', ['Timestamp', 'User', 'Action', 'Record type', 'Record', 'Changes', 'IP address'],
            collect($trail->present($logs))->map(fn ($l) => [
                $l['at'], $l['user'], $l['event'], $l['subject_type'], $l['subject'],
                $l['detail'] ?? collect($l['changes'])->map(fn ($c) => "{$c['field']}: ".($c['old'] ?? '').' -> '.($c['new'] ?? ''))->join('; '),
                $l['ip_address'],
            ]));
    }

    public function users(Request $request): StreamedResponse
    {
        AccessLog::exported($request, AccessLog::USER_LIST, 'User accounts list (CSV)');

        return TableExport::csv('User_Accounts_'.now()->format('Y-m-d').'.csv', ['Name', 'Username', 'Email', 'Role', 'Status', 'Last sign-in'],
            User::orderBy('last_name')->get()->map(fn (User $u) => [
                $u->name, $u->username, $u->email, $u->role->label(), $u->is_active ? 'Active' : 'Inactive', $u->last_login_at?->format('Y-m-d H:i'),
            ]));
    }

    public function template(Request $request, EncodingTemplate $template): Response
    {
        return $request->query('format') === 'csv' ? $template->csv() : $template->download();
    }

    /**
     * Every table as a CSV inside one ZIP, so the school keeps its own copy
     * of the data. Passwords and session data are left out.
     */
    public function backup(Request $request): BinaryFileResponse
    {
        AccessLog::exported($request, AccessLog::BACKUP, 'Full backup of every table (ZIP)');
        $tables = ['school_years', 'strands', 'sections', 'students', 'enrollments', 'document_submissions', 'revision_requests', 'teacher_assignments', 'users', 'audit_logs'];
        $hidden = ['users' => ['password', 'remember_token']];
        $path = tempnam(sys_get_temp_dir(), 'bak');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($tables as $table) {
            $columns = array_values(array_diff(Schema::getColumnListing($table), $hidden[$table] ?? []));
            $csv = fopen('php://temp', 'w+');
            fwrite($csv, "\xEF\xBB\xBF");
            fputcsv($csv, $columns);
            DB::table($table)->select($columns)->orderBy('id')->chunk(1000, function ($rows) use ($csv, $columns) {
                foreach ($rows as $row) {
                    fputcsv($csv, array_map(fn ($c) => $row->{$c}, $columns));
                }
            });
            rewind($csv);
            $zip->addFromString("{$table}.csv", stream_get_contents($csv));
            fclose($csv);
        }

        $zip->close();

        AuditLog::create(['user_id' => auth()->id(), 'event' => 'backup', 'auditable_type' => 'system', 'auditable_id' => 0, 'ip_address' => request()->ip()]);

        return response()->download($path, 'DJIHS_Backup_'.now()->format('Y-m-d_His').'.zip')->deleteFileAfterSend();
    }
}
