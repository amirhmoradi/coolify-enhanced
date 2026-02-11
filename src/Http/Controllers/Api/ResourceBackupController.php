<?php

namespace AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api;

use AmirhMoradi\CoolifyEnhanced\Jobs\ResourceBackupJob;
use AmirhMoradi\CoolifyEnhanced\Models\ScheduledResourceBackup;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Visus\Cuid2\Cuid2;

class ResourceBackupController extends Controller
{
    public function __construct()
    {
        // Safety: abort if the feature has been disabled
        if (! config('coolify-enhanced.enabled', false)) {
            abort(404);
        }
    }

    /**
     * List resource backups for the current team.
     */
    public function index(Request $request)
    {
        $teamId = $request->user()->currentTeam()->id;

        $backups = ScheduledResourceBackup::where('team_id', $teamId)
            ->with('latest_log')
            ->get();

        return response()->json($backups);
    }

    /**
     * Create a new resource backup schedule.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'backup_type' => 'required|in:volume,configuration,full,coolify_instance',
            'resource_type' => 'required_unless:backup_type,coolify_instance|string',
            'resource_id' => 'required_unless:backup_type,coolify_instance|integer',
            'frequency' => 'nullable|string|max:100',
            'timezone' => 'nullable|string|max:100',
            'timeout' => 'nullable|integer|min:60',
            'save_s3' => 'nullable|boolean',
            'disable_local_backup' => 'nullable|boolean',
            's3_storage_id' => 'nullable|integer|exists:s3_storages,id',
            'retention_amount_locally' => 'nullable|integer|min:0',
            'retention_days_locally' => 'nullable|integer|min:0',
            'retention_amount_s3' => 'nullable|integer|min:0',
            'retention_days_s3' => 'nullable|integer|min:0',
        ]);

        $teamId = $request->user()->currentTeam()->id;

        // coolify_instance doesn't need a resource
        if ($validated['backup_type'] === 'coolify_instance') {
            $validated['resource_type'] = 'coolify_instance';
            $validated['resource_id'] = 0;
        }

        $backup = ScheduledResourceBackup::create(array_merge($validated, [
            'uuid' => (string) new Cuid2,
            'team_id' => $teamId,
            'enabled' => true,
            'frequency' => $validated['frequency'] ?? '0 2 * * *',
            'timeout' => $validated['timeout'] ?? 3600,
        ]));

        return response()->json($backup, 201);
    }

    /**
     * Show a specific resource backup schedule.
     */
    public function show(string $uuid)
    {
        $backup = ScheduledResourceBackup::where('uuid', $uuid)
            ->with('executions')
            ->firstOrFail();

        return response()->json($backup);
    }

    /**
     * Trigger a backup immediately.
     */
    public function trigger(string $uuid)
    {
        $backup = ScheduledResourceBackup::where('uuid', $uuid)->firstOrFail();
        ResourceBackupJob::dispatch($backup);

        return response()->json(['message' => 'Backup job dispatched']);
    }

    /**
     * Delete a resource backup schedule.
     */
    public function destroy(string $uuid)
    {
        $backup = ScheduledResourceBackup::where('uuid', $uuid)->firstOrFail();
        $backup->delete();

        return response()->json(['message' => 'Backup schedule deleted']);
    }
}
