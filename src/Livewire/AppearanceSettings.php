<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\EnhancedUiSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AppearanceSettings extends Component
{
    public ?string $activeTheme = null;

    public array $availableThemes = [];

    public function mount(): void
    {
        if (! config('coolify-enhanced.enabled', false)) {
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
        EnhancedUiSettings::setActiveTheme($this->activeTheme ?: null);
        $this->dispatch('success', 'Theme updated. Reload the page to see changes.');
    }

    public function render(): View
    {
        return view('coolify-enhanced::livewire.appearance-settings');
    }
}
