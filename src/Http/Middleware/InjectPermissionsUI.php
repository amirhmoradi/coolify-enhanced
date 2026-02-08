<?php

namespace AmirhMoradi\CoolifyPermissions\Http\Middleware;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpFoundation\Response;

class InjectPermissionsUI
{
    /**
     * Inject the granular permissions access matrix into Coolify's team admin page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false) {
            return $response;
        }

        $injection = $this->renderComponent();
        if (empty($injection)) {
            return $response;
        }

        // Wrap in a container with positioning script
        $wrapped = $this->wrapWithInjector($injection);

        // Inject before </body>
        $content = str_replace('</body>', $wrapped.'</body>', $content);
        $response->setContent($content);

        return $response;
    }

    /**
     * Determine if we should inject into this response.
     */
    protected function shouldInject(Request $request, Response $response): bool
    {
        // Only inject on the team admin page (/team/admin route)
        if (! $request->routeIs('team.admin-view')) {
            return false;
        }

        // Must be an HTML response
        $contentType = $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        // Must be successful
        if (! $response->isSuccessful()) {
            return false;
        }

        // Must be authenticated
        if (! auth()->check()) {
            return false;
        }

        // User must have admin/owner role to see the matrix
        if (! PermissionService::hasRoleBypass(auth()->user())) {
            return false;
        }

        return true;
    }

    /**
     * Render the Livewire access matrix component.
     */
    protected function renderComponent(): string
    {
        try {
            return Blade::render('@livewire(\'permissions::access-matrix\')');
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * Wrap the rendered component with a container and positioning script.
     *
     * Coolify's layout structure:
     *   <main class="lg:pl-56">
     *     <div class="p-4 sm:px-6 lg:px-8 lg:py-6">
     *       <div> <!-- Livewire component root (admin-view) -->
     *         <x-team.navbar />
     *         <h2>Admin View</h2>
     *         ...content...
     *       </div>
     *     </div>
     *   </main>
     *
     * We append our section inside the Livewire component root div.
     */
    protected function wrapWithInjector(string $componentHtml): string
    {
        return <<<HTML

<!-- Coolify Granular Permissions - Injected Access Matrix -->
<div id="granular-permissions-inject" style="display:none;">
    {$componentHtml}
</div>
<script data-navigate-once>
(function() {
    function isAdminPage() {
        return window.location.pathname === '/team/admin';
    }

    function positionPermissionsUI() {
        var wrapper = document.getElementById('granular-permissions-inject');
        if (!wrapper) return;

        // Only show on team admin page — hide on all other pages
        if (!isAdminPage()) {
            wrapper.style.display = 'none';
            wrapper.dataset.positioned = '';
            return;
        }

        if (wrapper.dataset.positioned === 'true') return;

        // Target: the Livewire admin-view component root div inside main content
        // Coolify structure: main.lg\\:pl-56 > div.p-4 > div (livewire root)
        var target = document.querySelector('main > div > div > div:first-child');

        // Fallback: find the div containing "Admin View" heading
        if (!target) {
            var headings = document.querySelectorAll('h2');
            for (var i = 0; i < headings.length; i++) {
                if (headings[i].textContent.trim() === 'Admin View') {
                    target = headings[i].closest('div');
                    break;
                }
            }
        }

        // Final fallback: main content padding div
        if (!target) {
            target = document.querySelector('main > div');
        }

        if (target && target !== wrapper) {
            target.appendChild(wrapper);
            wrapper.dataset.positioned = 'true';
        }

        wrapper.style.display = 'block';
    }

    // Run after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', positionPermissionsUI);
    } else {
        positionPermissionsUI();
    }

    // Re-run after Livewire SPA navigation (wire:navigate)
    document.addEventListener('livewire:navigated', function() {
        var wrapper = document.getElementById('granular-permissions-inject');
        if (wrapper) wrapper.dataset.positioned = '';
        setTimeout(positionPermissionsUI, 50);
    });
})();
</script>
<!-- End Coolify Granular Permissions -->

HTML;
    }
}
