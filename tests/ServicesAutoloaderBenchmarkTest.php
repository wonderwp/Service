<?php

declare(strict_types=1);

namespace WonderWp\Component\Service\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Benchmarks discovery-cache ON (warm hit) vs OFF.
 *
 * Important: isCacheValid() still runs discover() for the signature — so the
 * expected win is skipping token_get_all + ReflectionClass on each file, not
 * skipping filesystem discovery entirely.
 *
 * Run focused:
 *   vendor/bin/phpunit -c vendor/wonderwp/service/phpunit.xml.dist --filter ServicesAutoloaderBenchmarkTest
 */
final class ServicesAutoloaderBenchmarkTest extends TestCase
{
    use ServicesAutoloaderTestHelpers;

    private const FIXTURE_CLASSES = 60;
    private const ITERATIONS = 25;
    private const WARMUP = 3;
    /** Warm hit must be at least this fraction of cache-off median (lower = stricter). */
    private const MAX_HIT_RATIO = 0.70;

    protected function setUp(): void
    {
        $ns = 'WwpServiceBench' . str_replace('.', '', uniqid('', true));
        $this->setUpAutoloadFixture(self::FIXTURE_CLASSES, $ns);
    }

    protected function tearDown(): void
    {
        $this->tearDownAutoloadFixture();
    }

    public function test_warm_cache_hit_is_faster_than_cache_disabled(): void
    {
        $paths = $this->discoveryPaths();
        $cb = $this->noopCallback();

        // Prime: cold miss builds the FileCache entry + loads classes once.
        $primer = $this->makeService(true, true);
        $map = $primer->autoload([], $paths, $cb);
        $this->assertCount(self::FIXTURE_CLASSES, $map);

        $hitSamples = $this->sampleAutoload(true, $paths, $cb);
        $offSamples = $this->sampleAutoload(false, $paths, $cb);

        $hitMedianMs = $this->median($hitSamples);
        $offMedianMs = $this->median($offSamples);
        $ratio = $offMedianMs > 0.0 ? ($hitMedianMs / $offMedianMs) : INF;

        $report = [
            'fixture_classes' => self::FIXTURE_CLASSES,
            'iterations' => self::ITERATIONS,
            'warmup' => self::WARMUP,
            'cache_off_median_ms' => round($offMedianMs, 3),
            'warm_hit_median_ms' => round($hitMedianMs, 3),
            'hit_over_off_ratio' => round($ratio, 3),
            'required_max_ratio' => self::MAX_HIT_RATIO,
            'cache_off_samples_ms' => array_map(static fn (float $v): float => round($v, 3), $offSamples),
            'warm_hit_samples_ms' => array_map(static fn (float $v): float => round($v, 3), $hitSamples),
            'note' => 'Warm hit still pays discover() for discoverySignature validation; savings = token/Reflection skip.',
        ];

        fwrite(STDERR, "\n[wwp-service autoload benchmark]\n" . json_encode($report, JSON_PRETTY_PRINT) . "\n");

        $this->assertLessThan(
            $offMedianMs,
            $hitMedianMs,
            sprintf(
                'Expected warm cache hit (%.3f ms) to be faster than cache-off (%.3f ms). Full report on STDERR.',
                $hitMedianMs,
                $offMedianMs
            )
        );

        $this->assertLessThanOrEqual(
            self::MAX_HIT_RATIO,
            $ratio,
            sprintf(
                'Warm hit/off ratio %.3f exceeds %.2f — discovery cache is not delivering enough speedup. See STDERR report.',
                $ratio,
                self::MAX_HIT_RATIO
            )
        );
    }

    /**
     * @return float[] milliseconds per iteration (after warmup)
     */
    private function sampleAutoload(bool $cacheEnabled, array $paths, callable $cb): array
    {
        $service = $this->makeService($cacheEnabled, true);

        for ($i = 0; $i < self::WARMUP; $i++) {
            $service->autoload([], $paths, $cb);
        }

        $samples = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $service->resetStats();
            $start = hrtime(true);
            $service->autoload([], $paths, $cb);
            $samples[] = (hrtime(true) - $start) / 1e6;
        }

        return $samples;
    }
}
