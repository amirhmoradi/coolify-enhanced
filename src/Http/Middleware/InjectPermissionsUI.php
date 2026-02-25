<?php

namespace AmirhMoradi\CoolifyEnhanced\Http\Middleware;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InjectPermissionsUI
{
    /**
     * Inject UI components into Coolify pages:
     * - Access matrix on team admin page
     * - Cluster navigation in sidebar (when cluster management enabled)
     *
     * Note: Resource backup sidebar items are added via view overlays
     * (not middleware injection) so they integrate natively with
     * Coolify's configuration page sidebar and $currentRoute routing.
     *
     * Encryption settings are also injected via view overlay.
     * See src/Overrides/Views/livewire/storage/show.blade.php
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isInjectableResponse($response) || ! auth()->check()) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || empty($content)) {
            return $response;
        }

        $injections = '';

        if ($this->isTeamAdminPage($request) && PermissionService::hasRoleBypass(auth()->user())) {
            $component = $this->renderAccessMatrix();
            if (! empty($component)) {
                $injections .= $this->wrapWithInjector($component);
            }
        }

        if ($this->shouldInjectClusterNav()) {
            $injections .= $this->renderClusterNavInjection();
        }

        if (! empty($injections)) {
            $content = str_replace('</body>', $injections.'</body>', $content);
            $response->setContent($content);
        }

        return $response;
    }

    /**
     * Check if the response is injectable (HTML, successful).
     */
    protected function isInjectableResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html') && $response->isSuccessful();
    }

    /**
     * Check if this is the team admin page.
     */
    protected function isTeamAdminPage(Request $request): bool
    {
        // Try named route first, fall back to URL pattern
        if ($request->routeIs('team.admin-view')) {
            return true;
        }

        return $request->is('team/admin') || $request->is('team');
    }

    /**
     * Render the Livewire access matrix component.
     */
    protected function renderAccessMatrix(): string
    {
        try {
            return Blade::render('@livewire(\'enhanced::access-matrix\')');
        } catch (\Throwable $e) {
            Log::error('Coolify Enhanced: Failed to render access matrix', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Wrap the rendered component with a container and positioning script.
     */
    protected function wrapWithInjector(string $componentHtml): string
    {
        return <<<HTML

<!-- Coolify Enhanced - Injected Access Matrix -->
<div id="coolify-enhanced-inject" style="display:none;">
    {$componentHtml}
</div>
<script data-navigate-once>
(function() {
    function isAdminPage() {
        return window.location.pathname === '/team/admin';
    }

    function positionPermissionsUI() {
        var wrapper = document.getElementById('coolify-enhanced-inject');
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
        var wrapper = document.getElementById('coolify-enhanced-inject');
        if (wrapper) wrapper.dataset.positioned = '';
        setTimeout(positionPermissionsUI, 50);
    });
})();
</script>
<!-- End Coolify Enhanced - Access Matrix -->

HTML;
    }

    /**
     * Check if cluster navigation should be injected into the sidebar.
     * Requires feature flags enabled and at least one cluster for the team.
     */
    protected function shouldInjectClusterNav(): bool
    {
        if (! config('coolify-enhanced.enabled')) {
            return false;
        }

        if (! config('coolify-enhanced.cluster_management')) {
            return false;
        }

        $teamId = auth()->user()->currentTeam()?->id;
        if (! $teamId) {
            return false;
        }

        return Cache::remember(
            "cluster-nav-visible:{$teamId}",
            60,
            fn () => Cluster::where('team_id', $teamId)->exists()
        );
    }

    /**
     * Render the cluster navigation sidebar injection snippet.
     * Inserts a "Clusters" link after the "Servers" link in Coolify's sidebar.
     */
    protected function renderClusterNavInjection(): string
    {
        return <<<'HTML'

<!-- Coolify Enhanced - Cluster Navigation -->
<div id="coolify-enhanced-cluster-nav" style="display:none;">
    <a class="menu-item" href="/clusters">
        <svg xmlns="http://www.w3.org/2000/svg" class="menu-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="2" width="6" height="6" rx="1"/>
            <rect x="16" y="2" width="6" height="6" rx="1"/>
            <rect x="9" y="14" width="6" height="6" rx="1"/>
            <path d="M5 8v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/>
            <path d="M12 12v2"/>
        </svg>
        <span class="menu-item-label">Clusters</span>
    </a>
</div>
<script data-navigate-once>
(function() {
    function injectClusterNav() {
        var nav = document.getElementById('coolify-enhanced-cluster-nav');
        if (!nav || nav.dataset.injected === 'true') return;

        var serverLinks = document.querySelectorAll('a.menu-item');
        var serversLink = null;
        for (var i = 0; i < serverLinks.length; i++) {
            if (serverLinks[i].getAttribute('href') === '/servers') {
                serversLink = serverLinks[i];
                break;
            }
        }

        if (serversLink) {
            var clusterLink = nav.querySelector('a');
            if (clusterLink) {
                if (window.location.pathname.startsWith('/cluster')) {
                    clusterLink.classList.add('menu-item-active');
                }
                serversLink.parentNode.insertBefore(clusterLink, serversLink.nextSibling);
                nav.dataset.injected = 'true';
            }
        }
        nav.style.display = 'none';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectClusterNav);
    } else {
        injectClusterNav();
    }
    document.addEventListener('livewire:navigated', function() {
        var nav = document.getElementById('coolify-enhanced-cluster-nav');
        if (nav) nav.dataset.injected = '';
        setTimeout(injectClusterNav, 50);
    });
})();
</script>
<!-- End Coolify Enhanced - Cluster Navigation -->

HTML;
    }
}
