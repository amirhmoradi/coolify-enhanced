<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use App\Models\S3Storage;
use Livewire\Component;

/**
 * Livewire component for managing S3 backup encryption settings.
 *
 * Rendered via view overlay on the storage show page. Uses Coolify's
 * native form components (<x-forms.checkbox>, <x-forms.input>, etc.)
 * to ensure proper styling and Livewire hydration.
 *
 * Note: We do NOT call $this->authorize() in mount() because the
 * storage page itself already authorizes access via StorageShow::mount().
 */
class StorageEncryptionForm extends Component
{
    public ?int $storageId = null;

    // S3 path prefix (PR #7776)
    public ?string $path = null;

    public bool $encryptionEnabled = false;

    public string $encryptionPassword = '';

    public string $encryptionSalt = '';

    public string $filenameEncryption = 'off';

    public bool $directoryNameEncryption = false;

    public string $saveMessage = '';

    public string $saveStatus = '';

    protected function rules(): array
    {
        return [
            'path' => ['nullable', 'max:255', 'regex:/^[a-zA-Z0-9\/\-\_\.]*$/', 'not_regex:/\.\./'],
            'encryptionEnabled' => ['boolean'],
            'encryptionPassword' => ['required_if:encryptionEnabled,true', 'max:255'],
            'encryptionSalt' => ['nullable', 'max:255'],
            'filenameEncryption' => ['in:off,standard,obfuscate'],
            'directoryNameEncryption' => ['boolean'],
        ];
    }

    public function mount(int $storageId): void
    {
        $storage = S3Storage::find($storageId);
        if (! $storage) {
            return;
        }

        $this->storageId = $storage->id;

        // Gracefully handle case where columns don't exist yet
        // (migration may not have run)
        try {
            $this->path = $storage->path ?? null;
            $this->encryptionEnabled = (bool) ($storage->encryption_enabled ?? false);
            $this->encryptionPassword = $storage->encryption_password ?? '';
            $this->encryptionSalt = $storage->encryption_salt ?? '';
            $this->filenameEncryption = $storage->filename_encryption ?? 'off';
            $this->directoryNameEncryption = (bool) ($storage->directory_name_encryption ?? false);
        } catch (\Throwable $e) {
            // Columns might not exist yet - use defaults
            $this->path = null;
            $this->encryptionEnabled = false;
            $this->encryptionPassword = '';
            $this->encryptionSalt = '';
            $this->filenameEncryption = 'off';
            $this->directoryNameEncryption = false;
        }
    }

    /**
     * Called by the checkbox's instantSave to toggle encryption and re-render.
     * The wire:model binding already flips $encryptionEnabled before this runs.
     */
    public function toggleEncryption(): void
    {
        $this->saveMessage = '';
        $this->saveStatus = '';
    }

    public function save(): void
    {
        try {
            $this->validate();

            $storage = S3Storage::findOrFail($this->storageId);

            if ($this->encryptionEnabled && empty($this->encryptionPassword)) {
                $this->dispatch('error', 'Encryption password is required when encryption is enabled.');

                return;
            }

            // If filename encryption is off, force directory name encryption off
            if ($this->filenameEncryption === 'off') {
                $this->directoryNameEncryption = false;
            }

            // Save S3 path prefix
            $storage->path = filled($this->path) ? $this->path : null;

            // Save encryption settings
            $storage->encryption_enabled = $this->encryptionEnabled;
            $storage->encryption_password = $this->encryptionEnabled ? $this->encryptionPassword : null;
            $storage->encryption_salt = $this->encryptionEnabled ? ($this->encryptionSalt ?: null) : null;
            $storage->filename_encryption = $this->encryptionEnabled ? $this->filenameEncryption : 'off';
            $storage->directory_name_encryption = $this->encryptionEnabled ? $this->directoryNameEncryption : false;
            $storage->save();

            $this->saveMessage = 'Settings saved.';
            $this->saveStatus = 'success';
            $this->dispatch('success', 'Storage settings saved successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->saveMessage = 'Failed to save: '.$e->getMessage();
            $this->saveStatus = 'error';
            $this->dispatch('error', 'Failed to save encryption settings.', $e->getMessage());
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.storage-encryption-form');
    }
}
