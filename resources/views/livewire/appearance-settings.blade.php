<div>
    <x-slot:title>
        Appearance | Coolify
    </x-slot>
    <x-settings.navbar />

    <h2>Appearance</h2>
    <div class="subtitle">
        Choose a visual theme for the Coolify dashboard. Themes change colors, typography, and visual styling without altering layout or behavior.
    </div>

    <div class="flex flex-col gap-2 pt-4 max-w-md">
        <label for="activeTheme" class="text-sm font-medium">Dashboard Theme</label>
        <select id="activeTheme" wire:model="activeTheme" wire:change="saveTheme" class="input w-full">
            <option value="">Default (Coolify)</option>
            @foreach ($availableThemes as $slug => $theme)
                <option value="{{ $slug }}">{{ $theme['label'] }}{{ $theme['font_label'] ? ' — ' . $theme['font_label'] . ' font' : '' }}</option>
            @endforeach
        </select>
        @if ($activeTheme && isset($availableThemes[$activeTheme]))
            <p class="text-sm pt-1">{{ $availableThemes[$activeTheme]['description'] }}</p>
        @endif
        <p class="text-xs pt-2">Reload the page after changing themes to see the new styling applied.</p>
    </div>
</div>
