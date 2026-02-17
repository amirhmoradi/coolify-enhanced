<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Jobs\SyncTemplateSourceJob;
use AmirhMoradi\CoolifyEnhanced\Models\CustomTemplateSource;
use AmirhMoradi\CoolifyEnhanced\Services\TemplateSourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class CustomTemplateSources extends Component
{
    // Form fields for adding/editing a source
    public ?int $editingSourceId = null;

    public string $formName = '';

    public string $formRepositoryUrl = '';

    public string $formBranch = 'main';

    public string $formFolderPath = 'templates/compose';

    public string $formAuthToken = '';

    public bool $showForm = false;

    public ?string $expandedSourceUuid = null;

    protected function rules(): array
    {
        return [
            'formName' => ['required', 'min:2', 'max:100'],
            'formRepositoryUrl' => ['required', 'max:500', 'regex:/^https?:\/\//'],
            'formBranch' => ['required', 'max:100', 'regex:/^[a-zA-Z0-9\.\-\_\/]+$/'],
            'formFolderPath' => ['required', 'max:500', 'regex:/^[a-zA-Z0-9\/\-\_\.]+$/', 'not_regex:/\.\./'],
            'formAuthToken' => ['nullable', 'max:500'],
        ];
    }

    protected $messages = [
        'formName.required' => 'A display name is required.',
        'formRepositoryUrl.required' => 'A GitHub repository URL is required.',
        'formRepositoryUrl.regex' => 'Repository URL must start with http:// or https://.',
        'formBranch.regex' => 'Branch name contains invalid characters.',
        'formFolderPath.regex' => 'Folder path contains invalid characters.',
        'formFolderPath.not_regex' => 'Folder path cannot contain "..".',
    ];

    public function mount(): void
    {
        if (! $this->isAuthorized()) {
            abort(403);
        }
    }

    public function render(): View
    {
        $sources = CustomTemplateSource::orderBy('name')->get();

        return view('coolify-enhanced::livewire.custom-template-sources', [
            'sources' => $sources,
        ]);
    }

    /**
     * Show the add form with default values.
     */
    public function showAddForm(): void
    {
        $this->authorize();
        $this->resetForm();
        $this->editingSourceId = null;
        $this->showForm = true;
    }

    /**
     * Show the edit form populated with source data.
     */
    public function editSource(int $sourceId): void
    {
        $this->authorize();
        $source = CustomTemplateSource::findOrFail($sourceId);
        $this->editingSourceId = $source->id;
        $this->formName = $source->name;
        $this->formRepositoryUrl = $source->repository_url;
        $this->formBranch = $source->branch;
        $this->formFolderPath = $source->folder_path;
        $this->formAuthToken = ''; // Never expose the token back
        $this->showForm = true;
    }

    /**
     * Cancel form editing.
     */
    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->editingSourceId = null;
    }

    /**
     * Save and validate the source, then trigger a sync.
     */
    public function saveSource(): void
    {
        $this->authorize();
        $this->validate();

        try {
            // Validate the GitHub connection first
            $validation = TemplateSourceService::validateSource(
                $this->formRepositoryUrl,
                $this->formBranch,
                $this->formFolderPath,
                filled($this->formAuthToken) ? $this->formAuthToken : null
            );

            if (! $validation['valid']) {
                $this->dispatch('error', 'Connection failed: '.$validation['error']);

                return;
            }

            if ($this->editingSourceId) {
                $source = CustomTemplateSource::findOrFail($this->editingSourceId);
                $source->name = $this->formName;
                $source->repository_url = $this->formRepositoryUrl;
                $source->branch = $this->formBranch;
                $source->folder_path = $this->formFolderPath;
                if (filled($this->formAuthToken)) {
                    $source->auth_token = $this->formAuthToken;
                }
                $source->save();
            } else {
                $source = CustomTemplateSource::create([
                    'name' => $this->formName,
                    'repository_url' => $this->formRepositoryUrl,
                    'branch' => $this->formBranch,
                    'folder_path' => $this->formFolderPath,
                    'auth_token' => filled($this->formAuthToken) ? $this->formAuthToken : null,
                ]);
            }

            SyncTemplateSourceJob::dispatch($source);

            $this->cancelForm();
            $this->dispatch('success', "Source \"{$source->name}\" saved. Syncing {$validation['file_count']} templates...");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to save source: '.$e->getMessage());
        }
    }

    /**
     * Sync a single source.
     */
    public function syncSource(int $sourceId): void
    {
        $this->authorize();

        try {
            $source = CustomTemplateSource::findOrFail($sourceId);
            SyncTemplateSourceJob::dispatch($source);
            $this->dispatch('success', "Syncing \"{$source->name}\"...");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to start sync: '.$e->getMessage());
        }
    }

    /**
     * Sync all enabled sources.
     */
    public function syncAll(): void
    {
        $this->authorize();

        try {
            $sources = CustomTemplateSource::where('enabled', true)->get();
            foreach ($sources as $source) {
                SyncTemplateSourceJob::dispatch($source);
            }
            $this->dispatch('success', "Syncing {$sources->count()} sources...");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to start sync: '.$e->getMessage());
        }
    }

    /**
     * Toggle a source's enabled status.
     */
    public function toggleEnabled(int $sourceId): void
    {
        $this->authorize();

        try {
            $source = CustomTemplateSource::findOrFail($sourceId);
            $source->enabled = ! $source->enabled;
            $source->save();

            $status = $source->enabled ? 'enabled' : 'disabled';
            $this->dispatch('success', "Source \"{$source->name}\" {$status}.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to toggle source: '.$e->getMessage());
        }
    }

    /**
     * Delete a source and its cached templates.
     */
    public function deleteSource(int $sourceId): void
    {
        $this->authorize();

        try {
            $source = CustomTemplateSource::findOrFail($sourceId);
            $name = $source->name;

            TemplateSourceService::deleteCachedTemplates($source);
            $source->delete();

            $this->dispatch('success', "Source \"{$name}\" deleted.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete source: '.$e->getMessage());
        }
    }

    /**
     * Toggle the expanded templates list for a source.
     */
    public function toggleExpanded(string $uuid): void
    {
        $this->expandedSourceUuid = $this->expandedSourceUuid === $uuid ? null : $uuid;
    }

    /**
     * Get the template list for an expanded source.
     *
     * @return array<string, mixed>
     */
    public function getExpandedTemplatesProperty(): array
    {
        if (! $this->expandedSourceUuid) {
            return [];
        }

        $source = CustomTemplateSource::where('uuid', $this->expandedSourceUuid)->first();
        if (! $source) {
            return [];
        }

        return $source->loadCachedTemplates();
    }

    /**
     * Check authorization and abort if not allowed.
     */
    protected function authorize(): void
    {
        if (! $this->isAuthorized()) {
            abort(403);
        }
    }

    /**
     * Check if the current user is authorized to manage template sources.
     */
    protected function isAuthorized(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $teamRole = $user->teams?->first()?->pivot?->role ?? null;

        return in_array($teamRole, ['owner', 'admin']);
    }

    /**
     * Reset the form to defaults.
     */
    protected function resetForm(): void
    {
        $this->formName = '';
        $this->formRepositoryUrl = '';
        $this->formBranch = 'main';
        $this->formFolderPath = 'templates/compose';
        $this->formAuthToken = '';
        $this->resetValidation();
    }
}
