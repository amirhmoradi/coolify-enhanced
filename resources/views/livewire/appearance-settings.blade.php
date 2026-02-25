<div>
    <x-slot:title>
        Appearance | Coolify
    </x-slot>
    <x-settings.navbar />

    <div class="flex flex-col gap-6">
        <div>
            <h2 class="pb-2">Appearance</h2>
            <div class="pb-4 text-sm text-neutral-600 dark:text-neutral-400">
                Optional corporate-grade modern UI theme with a refined color palette and light/dark modes.
                No structural changes — only visual refinements. Reload the page after toggling to see the effect.
            </div>

            <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent max-w-xl">
                <x-forms.checkbox
                    id="enhancedThemeEnabled"
                    instantSave="saveEnhancedTheme"
                    label="Use enhanced theme"
                    helper="When enabled, applies a modern, sophisticated color palette and refined typography across the dashboard. Works with both light and dark mode."
                />
            </div>
        </div>
    </div>
</div>
