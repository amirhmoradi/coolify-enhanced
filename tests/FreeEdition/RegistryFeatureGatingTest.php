<?php

use CorelixIo\Platform\Support\Feature;

/**
 * @group free
 */

beforeEach(function () {
    Feature::flush();
});

test('docker registry management is registered as pro feature', function () {
    expect(Feature::tier('DOCKER_REGISTRY_MANAGEMENT'))->toBe('pro');
});

test('docker registry management has correct metadata', function () {
    $meta = Feature::meta('DOCKER_REGISTRY_MANAGEMENT');
    expect($meta)->not->toBeNull();
    expect($meta['name'])->toBe('Docker Registry Management');
    expect($meta['tier'])->toBe('pro');
    expect($meta['category'])->toBe('deployment');
    expect($meta['env_var'])->toBe('FEATURE_DOCKER_REGISTRY_MANAGEMENT');
});

test('docker registry management enabled by default in pro build', function () {
    config(['features.edition' => 'pro']);
    Feature::flush();
    expect(Feature::enabled('DOCKER_REGISTRY_MANAGEMENT'))->toBeTrue();
});

test('docker registry management disabled via env var', function () {
    config(['features.edition' => 'pro']);
    putenv('FEATURE_DOCKER_REGISTRY_MANAGEMENT=false');
    Feature::flush();
    expect(Feature::enabled('DOCKER_REGISTRY_MANAGEMENT'))->toBeFalse();
    putenv('FEATURE_DOCKER_REGISTRY_MANAGEMENT');
});

test('docker registry management disabled in free edition', function () {
    config(['features.edition' => 'free']);
    putenv('FEATURE_DOCKER_REGISTRY_MANAGEMENT=false');
    Feature::flush();
    expect(Feature::disabled('DOCKER_REGISTRY_MANAGEMENT'))->toBeTrue();
    putenv('FEATURE_DOCKER_REGISTRY_MANAGEMENT');
});

test('docker registry management appears in Feature::all output', function () {
    $all = Feature::all();
    expect($all)->toHaveKey('DOCKER_REGISTRY_MANAGEMENT');
});

test('docker registry management appears in frontend flags', function () {
    $flags = Feature::frontendFlags();
    expect($flags)->toHaveKey('DOCKER_REGISTRY_MANAGEMENT');
});
