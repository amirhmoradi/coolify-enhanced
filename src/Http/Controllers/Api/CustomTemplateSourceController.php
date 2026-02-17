<?php

namespace AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api;

use AmirhMoradi\CoolifyEnhanced\Jobs\SyncTemplateSourceJob;
use AmirhMoradi\CoolifyEnhanced\Models\CustomTemplateSource;
use AmirhMoradi\CoolifyEnhanced\Services\TemplateSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class CustomTemplateSourceController extends Controller
{
    public function __construct()
    {
        if (! config('coolify-enhanced.enabled', false)) {
            abort(404);
        }

        // Only admins and owners can manage template sources
        $user = auth()->user();
        if ($user) {
            $teamRole = $user->teams?->first()?->pivot?->role ?? null;
            if (! in_array($teamRole, ['owner', 'admin'])) {
                abort(403, 'Only admins and owners can manage template sources.');
            }
        }
    }

    /**
     * List all custom template sources.
     */
    public function index(): JsonResponse
    {
        $sources = CustomTemplateSource::orderBy('name')->get();

        return response()->json($sources);
    }

    /**
     * Create a new template source.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'repository_url' => ['required', 'string', 'max:500', 'regex:/^https?:\/\//'],
            'branch' => ['string', 'max:100', 'regex:/^[a-zA-Z0-9\.\-\_\/]+$/'],
            'folder_path' => ['string', 'max:500', 'regex:/^[a-zA-Z0-9\/\-\_\.]+$/', 'not_regex:/\.\./'],
            'auth_token' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
        ]);

        $source = CustomTemplateSource::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'repository_url' => $validated['repository_url'],
            'branch' => $validated['branch'] ?? 'main',
            'folder_path' => $validated['folder_path'] ?? 'templates/compose',
            'auth_token' => $validated['auth_token'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
        ]);

        SyncTemplateSourceJob::dispatch($source);

        return response()->json($source, 201);
    }

    /**
     * Show a single template source.
     */
    public function show(string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        return response()->json($source);
    }

    /**
     * Update a template source.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['string', 'min:2', 'max:100'],
            'repository_url' => ['string', 'max:500', 'regex:/^https?:\/\//'],
            'branch' => ['string', 'max:100', 'regex:/^[a-zA-Z0-9\.\-\_\/]+$/'],
            'folder_path' => ['string', 'max:500', 'regex:/^[a-zA-Z0-9\/\-\_\.]+$/', 'not_regex:/\.\./'],
            'auth_token' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
        ]);

        $source->update($validated);

        return response()->json($source->fresh());
    }

    /**
     * Delete a template source and its cached templates.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        TemplateSourceService::deleteCachedTemplates($source);
        $source->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Sync a single template source.
     */
    public function sync(string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        SyncTemplateSourceJob::dispatch($source);

        return response()->json(['message' => 'Sync started.']);
    }

    /**
     * Sync all enabled template sources.
     */
    public function syncAll(): JsonResponse
    {
        $sources = CustomTemplateSource::where('enabled', true)->get();

        foreach ($sources as $source) {
            SyncTemplateSourceJob::dispatch($source);
        }

        return response()->json([
            'message' => "Syncing {$sources->count()} sources.",
        ]);
    }
}
