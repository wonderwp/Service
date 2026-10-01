<?php

declare(strict_types=1);

namespace WonderWp\Component\Service\Tests;

use PHPUnit\Framework\TestCase;

final class ServicesAutoloaderServiceTest extends TestCase
{
    use ServicesAutoloaderTestHelpers;

    private string $ns = '';

    protected function setUp(): void
    {
        $this->ns = 'WwpServiceCorrectness' . str_replace('.', '', uniqid('', true));
        $this->setUpAutoloadFixture(12, $this->ns);
    }

    protected function tearDown(): void
    {
        $this->tearDownAutoloadFixture();
    }

    public function test_warm_hit_returns_same_class_map_as_cold_miss(): void
    {
        $service = $this->makeService(true);
        $paths = $this->discoveryPaths();
        $cb = $this->noopCallback();

        $cold = $service->autoload([], $paths, $cb);
        $this->assertNotEmpty($cold);
        $this->assertCount(12, $cold);

        $warm = $service->autoload([], $paths, $cb);
        $this->assertSame($cold, $warm);
    }

    public function test_cache_disabled_still_discovers_classes(): void
    {
        $service = $this->makeService(false);
        $map = $service->autoload([], $this->discoveryPaths(), $this->noopCallback());

        $this->assertCount(12, $map);
        foreach ($map as $file => $class) {
            $this->assertFileExists($file);
            $this->assertStringStartsWith($this->ns . '\\BenchService', $class);
        }
    }

    public function test_adding_a_file_invalidates_cache(): void
    {
        $service = $this->makeService(true);
        $paths = $this->discoveryPaths();
        $cb = $this->noopCallback();

        $before = $service->autoload([], $paths, $cb);
        $this->assertCount(12, $before);

        $this->addFixtureClass('BenchServiceAdded', $this->ns);

        $after = $service->autoload([], $paths, $cb);
        $this->assertCount(13, $after);
        $this->assertContains($this->ns . '\\BenchServiceAdded', $after);
    }

    public function test_modifying_a_file_invalidates_cache(): void
    {
        $service = $this->makeService(true);
        $paths = $this->discoveryPaths();
        $cb = $this->noopCallback();

        $map = $service->autoload([], $paths, $cb);
        $file = array_key_first($map);
        $this->assertIsString($file);

        // Ensure mtime changes even on fast filesystems.
        clearstatcache(true, $file);
        $originalMtime = filemtime($file);
        touch($file, $originalMtime + 2);
        clearstatcache(true, $file);

        $after = $service->autoload([], $paths, $cb);
        $this->assertSame(array_values($map), array_values($after));
        $this->assertCount(12, $after);
    }

    public function test_deleting_a_file_invalidates_cache(): void
    {
        $service = $this->makeService(true);
        $paths = $this->discoveryPaths();
        $cb = $this->noopCallback();

        $map = $service->autoload([], $paths, $cb);
        $file = array_key_first($map);
        $this->assertIsString($file);
        unlink($file);

        $after = $service->autoload([], $paths, $cb);
        $this->assertCount(11, $after);
        $this->assertArrayNotHasKey($file, $after);
    }
}
