<?php

namespace AmirhMoradi\CoolifyEnhanced\Http\Middleware;

use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InjectPermissionsUI
{
    /**
     * Inject UI components into Coolify pages:
     * - Access matrix on team admin page
     * - "Resource Backups" tab on resource detail pages (Application, Service, Database)
     *
     * Note: Encryption settings are injected via view overlay (not middleware)
     * to ensure proper Livewire hydration. See src/Overrides/Views/livewire/storage/show.blade.php
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Must be HTML, successful, and authenticated
        if (! $this->isInjectableResponse($response) || ! auth()->check()) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || empty($content)) {
            return $response;
        }

        $injections = '';

        // Inject access matrix on team admin page
        if ($this->isTeamAdminPage($request) && PermissionService::hasRoleBypass(auth()->user())) {
            $component = $this->renderAccessMatrix();
            if (! empty($component)) {
                $injections .= $this->wrapWithInjector($component);
            }
        }

        // Inject "Resource Backups" tab on resource detail pages
        if ($this->isResourcePage($request)) {
            $injections .= $this->buildBackupTabInjector($request);
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
     * Check if this is a resource detail page (application, service, or database).
     */
    protected function isResourcePage(Request $request): bool
    {
        return $request->routeIs('project.application.*')
            || $request->routeIs('project.service.*')
            || $request->routeIs('project.database.*');
    }

    /**
     * Build the JavaScript that injects a "Resource Backups" tab into the heading nav.
     *
     * The tab is a plain <a> link — no Livewire interactivity — so middleware
     * injection works fine (unlike interactive forms that need view overlays).
     */
    protected function buildBackupTabInjector(Request $request): string
    {
        $routeName = null;
        $isActive = false;

        if ($request->routeIs('project.application.*')) {
            $routeName = 'project.application.resource-backups';
            $isActive = $request->routeIs('project.application.resource-backups');
        } elseif ($request->routeIs('project.service.*')) {
            $routeName = 'project.service.resource-backups';
            $isActive = $request->routeIs('project.service.resource-backups');
        } elseif ($request->routeIs('project.database.*')) {
            $routeName = 'project.database.resource-backups';
            $isActive = $request->routeIs('project.database.resource-backups');
        }

        if (! $routeName) {
            return '';
        }

        try {
            $url = route($routeName, $request->route()->parameters());
        } catch (\Throwable $e) {
            return '';
        }

        $activeClass = $isActive ? 'dark:text-white' : '';

        return <<<HTML

<!-- Coolify Enhanced - Resource Backups Tab -->
<script data-navigate-once>
(function() {
    function injectBackupTab() {
        // Find the heading navigation bar (inside .navbar-main or nav.pb-6)
        var navBars = document.querySelectorAll('.navbar-main nav');
        if (!navBars.length) {
            // Application heading uses nav > .navbar-main structure
            var outerNav = document.querySelector('nav.pb-6');
            if (outerNav) {
                navBars = outerNav.querySelectorAll('.navbar-main nav');
            }
        }

        var targetNav = null;
        for (var i = 0; i < navBars.length; i++) {
            var links = navBars[i].querySelectorAll('a');
            for (var j = 0; j < links.length; j++) {
                if (links[j].textContent.trim() === 'Configuration') {
                    targetNav = navBars[i];
                    break;
                }
            }
            if (targetNav) break;
        }

        if (!targetNav) return;
        if (targetNav.querySelector('[data-enhanced-backup-tab]')) return;

        var tabLink = document.createElement('a');
        tabLink.setAttribute('data-enhanced-backup-tab', 'true');
        tabLink.className = '{$activeClass}';
        tabLink.href = '{$url}';
        tabLink.setAttribute('wire:navigate', '');
        tabLink.textContent = 'Resource Backups';

        // Insert before component links (Links, x-applications.links, etc.)
        // which are typically the last child and are a div/span, not an <a>
        var lastNonLink = null;
        var children = targetNav.children;
        for (var k = children.length - 1; k >= 0; k--) {
            if (children[k].tagName !== 'A') {
                lastNonLink = children[k];
                break;
            }
        }

        if (lastNonLink) {
            targetNav.insertBefore(tabLink, lastNonLink);
        } else {
            targetNav.appendChild(tabLink);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectBackupTab);
    } else {
        injectBackupTab();
    }

    document.addEventListener('livewire:navigated', function() {
        setTimeout(injectBackupTab, 50);
    });
})();
</script>
<!-- End Coolify Enhanced - Resource Backups Tab -->

HTML;
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
}
