<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Models\EnhancedUiSettings;
use Livewire\Component;

/**
 * Settings page for enhanced UI theme (Appearance).
 *
 * Allows instance admins to enable or disable the optional corporate-grade
 * modern UI theme. Stored in enhanced_ui_settings; disabled by default.
 */
class AppearanceSettings extends Component
{
    public bool $enhancedThemeEnabled = false;

    public function mount(): void
    {
        if (! config('coolify-enhanced.enabled', false)) {
            abort(404);
        }

        if (! isInstanceAdmin()) {
            abort(403);
        }

        $this->enhancedThemeEnabled = (bool) EnhancedUiSettings::get('enhanced_theme_enabled', false);
    }

    public function saveEnhancedTheme(): void
    {
        EnhancedUiSettings::set('enhanced_theme_enabled', $this->enhancedThemeEnabled);
        $this->dispatch('success', $this->enhancedThemeEnabled ? 'Enhanced theme enabled. Reload the page to see changes.' : 'Enhanced theme disabled. Reload the page to see changes.');
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.appearance-settings');
    }
}
