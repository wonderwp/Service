<?php

declare(strict_types=1);

namespace WonderWp\Component\Service\Tests;

use WonderWp\Component\Cache\FileCache;
use WonderWp\Component\DependencyInjection\Container;
use WonderWp\Component\Service\Services\ServicesAutoloaderService;

/**
 * Test double: toggle cache, inject FileCache, expose metrics.
 */
final class TestableServicesAutoloaderService extends ServicesAutoloaderService
{
    private bool $cacheEnabled = true;

    public function setCacheEnabled(bool $enabled): self
    {
        $this->cacheEnabled = $enabled;

        return $this;
    }

    protected function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    public function getStats(): array
    {
        $ref = new \ReflectionProperty(ServicesAutoloaderService::class, 'stats');
        $ref->setAccessible(true);

        return $ref->getValue($this);
    }

    public function resetStats(): void
    {
        $ref = new \ReflectionProperty(ServicesAutoloaderService::class, 'stats');
        $ref->setAccessible(true);
        $ref->setValue($this, [
            'totalTime' => 0.0,
            'discoveryTime' => 0.0,
            'fileLoadingTime' => 0.0,
            'registrationTime' => 0.0,
            'filesDiscovered' => 0,
            'classesLoaded' => 0,
            'autoloadCalls' => 0,
            'discoveryPaths' => [],
        ]);
    }
}

/**
 * Shared helpers for fixture generation / FileCache wiring.
 */
trait ServicesAutoloaderTestHelpers
{
    private string $fixtureRoot = '';
    private string $cacheDir = '';

    protected function setUpAutoloadFixture(int $classCount = 40, string $namespace = 'WwpServiceBench'): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/wwp-service-autoload-' . uniqid('', true);
        $this->cacheDir = sys_get_temp_dir() . '/wwp-service-cache-' . uniqid('', true);

        mkdir($this->fixtureRoot . '/Services', 0777, true);
        mkdir($this->cacheDir, 0777, true);

        for ($i = 1; $i <= $classCount; $i++) {
            $className = sprintf('BenchService%03d', $i);
            $path = $this->fixtureRoot . '/Services/' . $className . '.php';
            $body = <<<PHP
<?php
namespace {$namespace};

class {$className}
{
    public function ping(): string
    {
        return '{$className}';
    }
}
PHP;
            file_put_contents($path, $body);
        }

        $container = Container::buildInstance();
        $container['wwp.cache.file'] = new FileCache($this->cacheDir);
    }

    protected function tearDownAutoloadFixture(): void
    {
        $this->removeTree($this->fixtureRoot);
        $this->removeTree($this->cacheDir);
    }

    protected function discoveryPaths(): array
    {
        return [$this->fixtureRoot . '/Services'];
    }

    protected function noopCallback(): callable
    {
        return static function (string $className, string $filePath): object {
            return new \stdClass();
        };
    }

    protected function makeService(bool $cacheEnabled = true, bool $metrics = true): TestableServicesAutoloaderService
    {
        $service = new TestableServicesAutoloaderService();
        $service->setCacheEnabled($cacheEnabled);
        $service->setMetricsEnabled($metrics);

        return $service;
    }

    protected function addFixtureClass(string $className, string $namespace = 'WwpServiceBench'): string
    {
        $path = $this->fixtureRoot . '/Services/' . $className . '.php';
        $body = <<<PHP
<?php
namespace {$namespace};

class {$className}
{
    public function ping(): string
    {
        return '{$className}';
    }
}
PHP;
        file_put_contents($path, $body);

        return $path;
    }

    protected function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * @param float[] $samples
     */
    protected function median(array $samples): float
    {
        sort($samples);
        $count = count($samples);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $samples[$mid];
        }

        return ($samples[$mid - 1] + $samples[$mid]) / 2;
    }
}
