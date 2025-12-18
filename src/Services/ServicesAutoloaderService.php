<?php

namespace WonderWp\Component\Service\Services;

use WonderWp\Component\Cache\FileCache;
use WonderWp\Component\DependencyInjection\Container;
use WonderWp\Component\Hook\Traits\HasHookManagerInterface;
use WonderWp\Component\PluginSkeleton\ManagerAwareInterface;
use WonderWp\Component\PluginSkeleton\ManagerInterface;
use WonderWp\Component\PluginSkeleton\Service\RegistrableInterface;

class ServicesAutoloaderService
{
    /**
     * Whether metrics collection is enabled.
     *
     * @var bool
     */
    private $metricsEnabled = false;

    /**
     * Aggregated statistics across all autoload() calls for this instance.
     *
     * @var array<string,mixed>
     */
    private $stats = [
        'totalTime' => 0.0,
        'discoveryTime' => 0.0,
        'fileLoadingTime' => 0.0,
        'registrationTime' => 0.0,
        'filesDiscovered' => 0,
        'classesLoaded' => 0,
        'autoloadCalls' => 0,
        // discoveryPaths is now an associative array: path => [files...]
        'discoveryPaths' => [],
    ];

    /**
     * Enable or disable metrics collection.
     *
     * @param bool $enabled
     * @return static
     */
    public function setMetricsEnabled(bool $enabled): static
    {
        $this->metricsEnabled = $enabled;

        return $this;
    }

    /**
     * Convenience method to enable metrics.
     *
     * @return static
     */
    public function enableMetrics(): static
    {
        return $this->setMetricsEnabled(true);
    }

    /**
     * Convenience method to disable metrics.
     *
     * @return static
     */
    public function disableMetrics(): static
    {
        return $this->setMetricsEnabled(false);
    }

    /**
     * Check if metrics collection is enabled.
     *
     * @return bool
     */
    public function isMetricsEnabled(): bool
    {
        return $this->metricsEnabled;
    }

    public function logStats()
    {
        if (!$this->metricsEnabled) {
            return;
        }

        dump($this->stats);
    }

    /**
     * @param array $classNameFromFiles
     * @param array $discoveryPaths
     * @param callable|null $successCallback
     * @param array $excludedClasses
     * @return array
     * You can take the result of the autoload method and pass it back via the $classNameFromFiles parameter to speed up the process
     */
    public function autoload(array $classNameFromFiles = [], array $discoveryPaths = [], callable $successCallback = null, array $excludedClasses = []): array
    {
        // Initialize metrics for this autoload() call (no-op if metrics are disabled)
        $metrics = $this->initMetrics();

        if (empty($discoveryPaths)) {
            throw new \RuntimeException('[WonderWp] You must provide at least one discovery path to autoload classes');
        }

        // Resolve default success callback
        $successCallback = $this->resolveSuccessCallback($successCallback);

        // First, try to use the cache. If we get a valid cached result, we can return early.
        $cachedResult = $this->autoloadFromCache($discoveryPaths, $classNameFromFiles, $successCallback, $metrics);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        // Fallback: discover classes on filesystem and register them
        $includedFiles = get_included_files();
        $this->discoverAndLoadFiles($discoveryPaths, $includedFiles, $classNameFromFiles, $excludedClasses, $metrics);
        $this->registerClasses($classNameFromFiles, $successCallback, $metrics);

        // Finalize metrics (if enabled) and cache the result
        $this->finalizeMetrics($metrics, $discoveryPaths);
        $this->storeInCache($classNameFromFiles, $discoveryPaths);

        return $classNameFromFiles;
    }

    /**
     * Initialize metrics array for autoload() call.
     *
     * @return array<string,mixed>
     */
    protected function initMetrics(): array
    {
        if (!$this->metricsEnabled) {
            return [];
        }

        return [
            'startTime' => microtime(true),
            'endTime' => null,
            'discoveryStartTime' => null,
            'discoveryEndTime' => null,
            'fileLoadingStartTime' => null,
            'fileLoadingEndTime' => null,
            'registrationStartTime' => null,
            'registrationEndTime' => null,
            'filesDiscovered' => 0,
            'classesLoaded' => 0,
            'filesByDiscoveryPath' => [],
        ];
    }

    /**
     * Mark the beginning of the discovery phase.
     *
     * @param array $metrics
     */
    protected function metricsDiscoveryStart(array &$metrics): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $metrics['discoveryStartTime'] = microtime(true);
    }

    /**
     * Mark the end of the discovery phase and record discovered files.
     *
     * @param array $metrics
     * @param array $filesToAutoLoad
     * @param array $discoveryPaths
     */
    protected function metricsDiscoveryEnd(array &$metrics, array $filesToAutoLoad, array $discoveryPaths): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $metrics['discoveryEndTime'] = microtime(true);
        $metrics['filesDiscovered'] = count($filesToAutoLoad);
        $metrics['filesByDiscoveryPath'] = $this->computeFilesByDiscoveryPath($discoveryPaths, $filesToAutoLoad);
    }

    /**
     * Mark the beginning of the file loading phase.
     *
     * @param array $metrics
     */
    protected function metricsFileLoadingStart(array &$metrics): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $metrics['fileLoadingStartTime'] = microtime(true);
    }

    /**
     * Mark the end of the file loading phase.
     *
     * @param array $metrics
     */
    protected function metricsFileLoadingEnd(array &$metrics): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $metrics['fileLoadingEndTime'] = microtime(true);
    }

    /**
     * Mark the beginning of the registration phase.
     *
     * @param array $metrics
     */
    protected function metricsRegistrationStart(array &$metrics): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $metrics['registrationStartTime'] = microtime(true);
    }

    /**
     * Mark the end of the registration phase.
     *
     * @param array $metrics
     */
    protected function metricsRegistrationEnd(array &$metrics): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $metrics['registrationEndTime'] = microtime(true);
    }

    /**
     * Increment the classesLoaded counter.
     *
     * @param array $metrics
     */
    protected function metricsIncrementClassesLoaded(array &$metrics): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        if (!isset($metrics['classesLoaded'])) {
            $metrics['classesLoaded'] = 0;
        }

        $metrics['classesLoaded']++;
    }

    /**
     * Set files information for a cache-hit scenario.
     *
     * @param array $metrics
     * @param array $discoveryPaths
     * @param array $classNameFromFiles
     */
    protected function metricsFilesFromCache(array &$metrics, array $discoveryPaths, array $classNameFromFiles): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        $files = array_keys($classNameFromFiles);
        $metrics['filesDiscovered'] = count($files);
        $metrics['filesByDiscoveryPath'] = $this->computeFilesByDiscoveryPath($discoveryPaths, $files);
    }

    /**
     * Finalize metrics collection for a call and aggregate them into global stats.
     *
     * @param array $metrics
     * @param array $discoveryPaths
     */
    protected function finalizeMetrics(array &$metrics, array $discoveryPaths): void
    {
        if (!$this->metricsEnabled || empty($metrics)) {
            return;
        }

        if (!isset($metrics['endTime']) || $metrics['endTime'] === null) {
            $metrics['endTime'] = microtime(true);
        }

        $this->updateStatsFromMetrics($metrics, $discoveryPaths);
    }

    /**
     * Ensure we always have a valid success callback.
     */
    protected function resolveSuccessCallback(?callable $successCallback): callable
    {
        if ($successCallback === null) {
            $successCallback = function ($className, $filePath) {
                return $this->autoloadFile($className, $filePath);
            };
        }

        return $successCallback;
    }

    /**
     * Check if cache system should be used.
     */
    protected function isCacheEnabled(): bool
    {
        return defined('WP_CACHE') && WP_CACHE;
    }

    /**
     * Try to autoload from cache. Returns null on cache miss/invalid cache,
     * or the populated $classNameFromFiles array on cache hit.
     *
     * @param array $discoveryPaths
     * @param array $classNameFromFiles
     * @param callable $successCallback
     * @param array $metrics
     * @return array|null
     */
    protected function autoloadFromCache(array $discoveryPaths, array &$classNameFromFiles, callable $successCallback, array &$metrics): ?array
    {
        if (!$this->isCacheEnabled()) {
            return null;
        }

        $cache = $this->getCacheInstance();
        if (!$cache) {
            return null;
        }

        // Generate cache key from discovery paths
        $cacheKey = $this->getCacheKey($discoveryPaths);

        // Try to load from cache
        $cachedData = $cache->get($cacheKey);
        if ($cachedData === null || !$this->isCacheValid($cachedData)) {
            return null;
        }

        // Cache hit - return cached classNameFromFiles
        $classNameFromFiles = $cachedData['classNameFromFiles'];

        // Still need to include files and register the classes
        if (!empty($classNameFromFiles)) {
            // Record file information for this cache-hit (no-op if metrics disabled)
            $this->metricsFilesFromCache($metrics, $discoveryPaths, $classNameFromFiles);

            // First, include all files (batch operation, faster than autoloader)
            // include_once already handles duplicates efficiently
            // @ suppresses warnings if file doesn't exist (edge case after cache validation)
            $this->metricsFileLoadingStart($metrics);
            foreach ($classNameFromFiles as $filePath => $className) {
                @include_once $filePath;
            }
            $this->metricsFileLoadingEnd($metrics);

            // Then, instantiate and register classes
            $this->metricsRegistrationStart($metrics);
            foreach ($classNameFromFiles as $filePath => $className) {
                if (class_exists($className)) {
                    $successCallback($className, $filePath);
                    $this->metricsIncrementClassesLoaded($metrics);
                }
            }
            $this->metricsRegistrationEnd($metrics);

            // Finalize metrics for this cache-hit autoload() call
            $this->finalizeMetrics($metrics, $discoveryPaths);
        }

        return $classNameFromFiles;
    }

    /**
     * Discover PHP files, include them and build the class map.
     *
     * @param array $discoveryPaths
     * @param array $includedFiles
     * @param array $classNameFromFiles
     * @param array $excludedClasses
     * @param array $metrics
     */
    protected function discoverAndLoadFiles(array $discoveryPaths, array $includedFiles, array &$classNameFromFiles, array &$excludedClasses, array &$metrics): void
    {
        // Metrics hooks (no-op if metrics disabled)
        $this->metricsDiscoveryStart($metrics);

        $filesToAutoLoad = $this->discover($discoveryPaths);
        $this->metricsDiscoveryEnd($metrics, $filesToAutoLoad, $discoveryPaths);
        $this->metricsFileLoadingStart($metrics);
        if (!empty($filesToAutoLoad)) {
            foreach ($filesToAutoLoad as $filePath) {
                if (!is_file($filePath)) {
                    continue;
                }

                if (!file_exists($filePath)) {
                    continue;
                }

                if (!in_array($filePath, $includedFiles)) {
                    include_once $filePath;
                }

                if (!isset($classNameFromFiles[$filePath])) {
                    // Get the class from the file
                    $classInfos = $this->getClassInfos($filePath);
                    if (!empty($classInfos['parentClass']) && !$classInfos['parentClass']->isAbstract()) {
                        $excludedClasses[] = $classInfos['parentClass']->getName();
                    }
                    if (!isset($classInfos['className']) || in_array($classInfos['className'], $excludedClasses, true)) {
                        continue;
                    }
                    $classNameFromFiles[$filePath] = $classInfos['className'];
                }
            }
        }

        $this->metricsFileLoadingEnd($metrics);
    }

    /**
     * Instantiate and register classes based on the discovered map.
     *
     * @param array $classNameFromFiles
     * @param callable $successCallback
     * @param array $metrics
     */
    protected function registerClasses(array $classNameFromFiles, callable $successCallback, array &$metrics): void
    {
        // Metrics hooks (no-op if metrics disabled)
        $this->metricsRegistrationStart($metrics);

        if (!empty($classNameFromFiles)) {
            foreach ($classNameFromFiles as $filePath => $className) {
                if (class_exists($className)) {
                    $successCallback($className, $filePath);
                    $this->metricsIncrementClassesLoaded($metrics);
                }
            }
        }
        $this->metricsRegistrationEnd($metrics);
    }

    /**
     * Aggregate metrics for the autoload() call into the global stats array.
     *
     * @param array $metrics
     * @param array $discoveryPaths
     */
    protected function updateStatsFromMetrics(array $metrics, array $discoveryPaths): void
    {
        if (!isset($metrics['startTime'], $metrics['endTime']) || $metrics['endTime'] === null) {
            return;
        }

        $totalTime = ($metrics['endTime'] - $metrics['startTime']) * 1000; // Convert to milliseconds
        $discoveryTime = ($metrics['discoveryStartTime'] && $metrics['discoveryEndTime'])
            ? (($metrics['discoveryEndTime'] - $metrics['discoveryStartTime']) * 1000)
            : 0;
        $fileLoadingTime = ($metrics['fileLoadingStartTime'] && $metrics['fileLoadingEndTime'])
            ? (($metrics['fileLoadingEndTime'] - $metrics['fileLoadingStartTime']) * 1000)
            : 0;
        $registrationTime = ($metrics['registrationStartTime'] && $metrics['registrationEndTime'])
            ? (($metrics['registrationEndTime'] - $metrics['registrationStartTime']) * 1000)
            : 0;

        $this->stats['totalTime'] += $totalTime;
        $this->stats['discoveryTime'] += $discoveryTime;
        $this->stats['fileLoadingTime'] += $fileLoadingTime;
        $this->stats['registrationTime'] += $registrationTime;
        $this->stats['filesDiscovered'] += (int) $metrics['filesDiscovered'];
        $this->stats['classesLoaded'] += (int) $metrics['classesLoaded'];
        $this->stats['autoloadCalls']++;

        // Ensure every discovery path has an entry, even if no files were discovered
        if (!empty($discoveryPaths)) {
            foreach ($discoveryPaths as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                if (!isset($this->stats['discoveryPaths'][$path])) {
                    $this->stats['discoveryPaths'][$path] = [];
                }
            }
        }

        // Merge per-call discovered files into global stats, grouped by discovery path
        if (!empty($metrics['filesByDiscoveryPath']) && is_array($metrics['filesByDiscoveryPath'])) {
            foreach ($metrics['filesByDiscoveryPath'] as $path => $files) {
                if (!is_array($files)) {
                    continue;
                }
                if (!isset($this->stats['discoveryPaths'][$path])) {
                    $this->stats['discoveryPaths'][$path] = [];
                }
                if (!empty($files)) {
                    $this->stats['discoveryPaths'][$path] = array_values(array_unique(array_merge(
                        $this->stats['discoveryPaths'][$path],
                        $files
                    )));
                }
            }
        }
    }

    /**
     * Save the autoload results into the cache, if enabled.
     *
     * @param array $classNameFromFiles
     * @param array $discoveryPaths
     */
    protected function storeInCache(array $classNameFromFiles, array $discoveryPaths): void
    {
        if (!$this->isCacheEnabled() || empty($classNameFromFiles)) {
            return;
        }

        $cache = $this->getCacheInstance();
        if (!$cache) {
            return;
        }

        // Collect file modification times for cache validation
        $fileMtimes = [];
        foreach (array_keys($classNameFromFiles) as $filePath) {
            if (file_exists($filePath)) {
                $fileMtimes[$filePath] = filemtime($filePath);
            }
        }

        // Save to cache
        $cacheKey = $this->getCacheKey($discoveryPaths);
        $cacheData = [
            'classNameFromFiles' => $classNameFromFiles,
            'fileMtimes' => $fileMtimes,
            'timestamp' => time(),
            'discoveryPaths' => $discoveryPaths,
        ];
        $cache->set($cacheKey, $cacheData, null); // No TTL, persistent until invalidation
    }

    /**
     * Build a map of discovery path => list of discovered files under that path.
     *
     * @param array $discoveryPaths
     * @param array $files
     * @return array<string,array>
     */
    protected function computeFilesByDiscoveryPath(array $discoveryPaths, array $files): array
    {
        $result = [];

        if (empty($discoveryPaths) || empty($files)) {
            return $result;
        }

        foreach ($discoveryPaths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (!isset($result[$path])) {
                $result[$path] = [];
            }

            foreach ($files as $file) {
                if (!is_string($file) || !is_file($file)) {
                    continue;
                }

                // Simple prefix check: file resides under this discovery path
                if (strpos($file, $path) === 0) {
                    $result[$path][] = $file;
                }
            }

            if (!empty($result[$path])) {
                $result[$path] = array_values(array_unique($result[$path]));
            }
        }

        return $result;
    }

    protected function getClassInfos(string $filePath)
    {
        $className = $this->getClassNameFromFile($filePath);
        if (empty($className)) {
            return [];
        }
        $reflection = new \ReflectionClass($className);
        $classInfos = [
            'className' => $className,
            'reflection' => $reflection,
            'parentClass' => $reflection->getParentClass(),
        ];
        return $classInfos;
    }

    public function deductDefaultDiscoveryPaths(array $discoveryPathsRoots, string $discoverFolderSuffix): array
    {
        if (empty($discoveryPathsRoots)) {
            return [];
        }

        $discoverFolderSuffixSlug = sanitize_title($discoverFolderSuffix);

        $defaultPaths = [];
        foreach ($discoveryPathsRoots as $key => $path) {
            $defaultPaths[$key . '-' . $discoverFolderSuffixSlug] = $path . $discoverFolderSuffix . DIRECTORY_SEPARATOR;
        }

        return $defaultPaths;
    }

    public function autoloadFile(string $className, string $filePath, ManagerInterface $manager = null): object
    {
        $instance = new $className();
        if ($instance instanceof ManagerAwareInterface && $manager instanceof ManagerInterface) {
            $instance->setManager($manager);
        }
        if ($instance instanceof HasHookManagerInterface) {
            $instance->setHookManager(Container::getInstance()['wwp.hook.manager']);
        }
        if ($instance instanceof RegistrableInterface) {
            $instance->register();
        }
        return $instance;
    }

    /**
     * Get cache key from discovery paths
     *
     * @param array $discoveryPaths
     * @return string
     */
    protected function getCacheKey(array $discoveryPaths): string
    {
        return 'autoload_' . md5(serialize($discoveryPaths));
    }

    /**
     * Get FileCache instance from container
     *
     * @return FileCache|null
     */
    protected function getCacheInstance()
    {
        try {
            $container = Container::getInstance();
            if ($container->offsetExists('wwp.cache.file')) {
                return $container->offsetGet('wwp.cache.file');
            }
        } catch (\Exception $e) {
            // Container not available or cache not registered
        }
        return null;
    }

    public function clearDiscoveryCache()
    {
        $cache = $this->getCacheInstance();
        if ($cache) {
            $cache->clear();
        }
    }

    /**
     * Validate cache data by checking file modification times
     *
     * @param array $cachedData
     * @return bool
     */
    protected function isCacheValid(array $cachedData): bool
    {
        if (!isset($cachedData['classNameFromFiles']) || !isset($cachedData['fileMtimes'])) {
            return false;
        }

        $fileMtimes = $cachedData['fileMtimes'];
        if (empty($fileMtimes)) {
            return true; // Empty cache is valid
        }

        // Check if all files still exist and have same mtime
        foreach ($fileMtimes as $filePath => $cachedMtime) {
            if (!file_exists($filePath)) {
                return false; // File deleted
            }

            $currentMtime = filemtime($filePath);
            if ($currentMtime !== $cachedMtime) {
                return false; // File modified
            }
        }

        return true;
    }

    /**
     * Extract the class name from a file
     * @param string $filePath
     * @return string
     */
    protected function getClassNameFromFile(string $filePath)
    {
        $content = file_get_contents($filePath);
        $namespace = $class = "";
        $tokens = token_get_all($content);
        if (empty($tokens)) {
            return '';
        }
        $count = count($tokens);
        for ($i = 2; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }
            if (isset($tokens[$i - 2]) && isset($tokens[$i - 2][1]) && $tokens[$i - 2][1] === 'namespace') {
                $namespace = $tokens[$i][1];
            }
            if (isset($tokens[$i - 2]) && isset($tokens[$i - 2][1]) && $tokens[$i - 2][1] === 'class') {
                $class = $tokens[$i][1];
                break;
            }
        }
        if (!empty($namespace) && !empty($class)) {
            return $namespace . '\\' . $class;
        }
        return '';
    }

    public function discover(array|string $directoryPath, array $files = []): array
    {
        if (is_array($directoryPath) && !empty($directoryPath)) {
            foreach ($directoryPath as $path) {
                if (is_dir($path)) {
                    $files = array_merge($files, $this->discover($path));
                } elseif (is_string($path) && class_exists($path)) {
                    $files[] = $path;
                }
            }
        } elseif (is_string($directoryPath) && is_dir($directoryPath)) {
            $scannedFiles = scandir($directoryPath);
            foreach ($scannedFiles as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $filePath = $directoryPath . DIRECTORY_SEPARATOR . $file;
                if (is_dir($filePath)) {
                    $files = array_merge($files, $this->discover($filePath));
                } elseif (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                    $files[] = $filePath;
                }
            }
        } elseif (is_string($directoryPath) && class_exists($directoryPath)) {
            $files[] = $directoryPath;
        }
        return apply_filters('wwp-abstractservice/files-discovery', $files, $directoryPath, $this, get_called_class());
    }
}
