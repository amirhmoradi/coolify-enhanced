<?php

use CorelixIo\Platform\Support\Feature;

/**
 * @group free
 */

beforeEach(function () {
    Feature::flush();
});

// --- Feature Registry Tests ---

test('feature registry loads all features', function () {
    $registry = Feature::registry();
    expect($registry)->toBeArray()->not->toBeEmpty();

    $keys = array_column($registry, 'key');
    expect($keys)->toContain('GRANULAR_PERMISSIONS');
    expect($keys)->toContain('CLUSTER_MANAGEMENT');
    expect($keys)->toContain('WHITELABELING');
});

test('every feature has required metadata fields', function () {
    foreach (Feature::registry() as $feature) {
        expect($feature)->toHaveKeys(['key', 'name', 'description', 'tier', 'default', 'category', 'env_var', 'parent']);
        expect($feature['tier'])->toBeIn(['free', 'pro']);
        expect($feature['default'])->toBeBool();
    }
});

test('feature registry has correct tier assignments', function () {
    expect(Feature::tier('GRANULAR_PERMISSIONS'))->toBe('free');
    expect(Feature::tier('ENCRYPTED_S3_BACKUPS'))->toBe('free');
    expect(Feature::tier('CLUSTER_MANAGEMENT'))->toBe('pro');
    expect(Feature::tier('WHITELABELING'))->toBe('pro');
});

// --- Feature Resolution Tests ---

test('all features enabled by default in private build', function () {
    $all = Feature::all();
    foreach ($all as $key => $enabled) {
        expect($enabled)->toBeTrue("Feature {$key} should be enabled by default");
    }
});

test('Feature::enabled returns true for enabled feature', function () {
    expect(Feature::enabled('GRANULAR_PERMISSIONS'))->toBeTrue();
});

test('Feature::disabled returns false for enabled feature', function () {
    expect(Feature::disabled('GRANULAR_PERMISSIONS'))->toBeFalse();
});

test('Feature::enabled returns false for unknown feature', function () {
    expect(Feature::enabled('NONEXISTENT_FEATURE'))->toBeFalse();
});

test('Feature::meta returns metadata for known feature', function () {
    $meta = Feature::meta('CLUSTER_MANAGEMENT');
    expect($meta)->not->toBeNull();
    expect($meta['name'])->toBe('Cluster Management');
    expect($meta['tier'])->toBe('pro');
    expect($meta['category'])->toBe('monitoring');
});

test('Feature::meta returns null for unknown feature', function () {
    expect(Feature::meta('NONEXISTENT'))->toBeNull();
});

test('Feature::edition returns edition from config', function () {
    config(['features.edition' => 'free']);
    Feature::flush();
    expect(Feature::edition())->toBe('free');
});

test('Feature::upgradeUrl returns URL from config', function () {
    expect(Feature::upgradeUrl())->toBeString()->not->toBeEmpty();
});

test('Feature::allEnabled returns only enabled features', function () {
    $enabled = Feature::allEnabled();
    foreach ($enabled as $key => $value) {
        expect($value)->toBeTrue();
    }
});

test('Feature::frontendFlags returns same as all()', function () {
    expect(Feature::frontendFlags())->toBe(Feature::all());
});

// --- Sub-feature Dependency Tests ---

test('sub-feature disabled when parent is disabled', function () {
    $registry = config('features.registry');
    foreach ($registry as &$feature) {
        if ($feature['key'] === 'NETWORK_MANAGEMENT') {
            $feature['default'] = false;
        }
    }
    config(['features.registry' => $registry]);
    Feature::flush();

    expect(Feature::enabled('NETWORK_MANAGEMENT'))->toBeFalse();
    expect(Feature::enabled('PROXY_ISOLATION'))->toBeFalse();
    expect(Feature::enabled('SWARM_OVERLAY_ENCRYPTION'))->toBeFalse();
});

// --- Feature::flush Tests ---

test('flush clears resolved cache', function () {
    $first = Feature::all();
    Feature::flush();
    $second = Feature::all();
    expect($first)->toBe($second);
});

// --- Tier Classification Tests ---

test('free tier features are correctly classified', function () {
    $freeKeys = ['GRANULAR_PERMISSIONS', 'ENCRYPTED_S3_BACKUPS', 'RESOURCE_BACKUPS',
        'CUSTOM_TEMPLATE_SOURCES', 'ENHANCED_DATABASE_CLASSIFICATION', 'NETWORK_MANAGEMENT',
        'PROXY_ISOLATION', 'SWARM_OVERLAY_ENCRYPTION', 'MCP_ENHANCED_TOOLS',
        'ENHANCED_UI_THEME', 'ADDITIONAL_BUILD_TYPES'];

    foreach ($freeKeys as $key) {
        expect(Feature::tier($key))->toBe('free', "Feature {$key} should be free tier");
    }
});

test('pro tier features are correctly classified', function () {
    $proKeys = ['CLUSTER_MANAGEMENT', 'WHITELABELING', 'DOCKER_REGISTRY_MANAGEMENT', 'AUDIT_TRAIL'];

    foreach ($proKeys as $key) {
        expect(Feature::tier($key))->toBe('pro', "Feature {$key} should be pro tier");
    }
});
