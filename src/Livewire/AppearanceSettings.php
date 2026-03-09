<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Models\EnhancedUiSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AppearanceSettings extends Component
{
    public ?string $activeTheme = null;

    public array $availableThemes = [];

    public function mount(): void
    {
        if (! config('corelix-platform.enabled', false)) {
            abort(404);
        }

        if (! isInstanceAdmin()) {
            abort(403);
        }

        $this->activeTheme = EnhancedUiSettings::getActiveTheme();
        $this->availableThemes = EnhancedUiSettings::getAvailableThemes();
    }

    public function saveTheme(): void
    {
        if ($this->activeTheme !== null && $this->activeTheme !== '') {
            $allowedSlugs = collect($this->availableThemes)->pluck('slug')->toArray();
            if (! in_array($this->activeTheme, $allowedSlugs, true)) {
                $this->addError('activeTheme', 'The selected theme is not available.');

                return;
            }
        }

        EnhancedUiSettings::setActiveTheme($this->activeTheme ?: null);
        $this->dispatch('success', 'Theme updated. Reload the page to see changes.');
    }

    public function render(): View
    {
        return view('corelix-platform::livewire.appearance-settings');
    }
}
