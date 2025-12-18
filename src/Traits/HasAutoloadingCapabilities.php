<?php

namespace WonderWp\Component\Service\Traits;

use WonderWp\Component\DependencyInjection\Container;
use WonderWp\Component\PluginSkeleton\ManagerInterface;
use WonderWp\Component\Service\Services\ServicesAutoloaderService;

trait HasAutoloadingCapabilities
{
    /** @var ServicesAutoloaderService|null */
    protected $servicesAutoloader;

    /**
     * Get the autoload service instance
     *
     * @return ServicesAutoloaderService|null
     */
    public function getServicesAutoloader(): ?ServicesAutoloaderService
    {
        if ($this->servicesAutoloader === null) {
            $this->servicesAutoloader = $this->loadServicesAutoloader();            
        }
        if ($this->servicesAutoloader === null) {
            throw new \RuntimeException('[WonderWp] ServicesAutoloader service could not be initialized. Manager is required.');
        }
        return $this->servicesAutoloader;
    }

    /**
     * Set the autoload service instance
     *
     * @param ServicesAutoloaderService $service
     * @return static
     */
    public function setServicesAutoloader(ServicesAutoloaderService $service): static
    {
        $this->servicesAutoloader = $service;
        return $this;
    }

    /**
     * Load the autoload service from container or create a new instance
     *
     * @return static
     */
    public function loadServicesAutoloader(): ?ServicesAutoloaderService
    {
        if ($this->servicesAutoloader === null) {
            $container = Container::getInstance();
            if ($container->offsetExists('wwp.service.servicesAutoloader')) {
                $this->servicesAutoloader = $container['wwp.service.servicesAutoloader'];
            }
        }
        return $this->servicesAutoloader;
    }

    /**
     * Autoload classes from discovery paths
     *
     * @param array $classNameFromFiles
     * @param array $discoveryPaths
     * @param callable|null $successCallback
     * @param array $excludedClasses
     * @return array
     */
    public function autoload(array $classNameFromFiles = [], array $discoveryPaths = [], callable $successCallback = null, array $excludedClasses = []): array
    {
        $classNameFromFiles = $this->resolveClassNameFromFiles($classNameFromFiles);
        $discoveryPaths     = $this->resolveDiscoveryPaths($discoveryPaths);
        $successCallback    = $this->resolveSuccessCallback($successCallback);
        $excludedClasses    = $this->resolveExcludedClasses($excludedClasses);

        $result = $this->getServicesAutoloader()->autoload(
            $classNameFromFiles,
            $discoveryPaths,
            $successCallback,
            $excludedClasses
        );

        return $this->afterAutoload($result, $classNameFromFiles, $discoveryPaths, $successCallback, $excludedClasses);
    }

    /**
     * Resolver for initial classNameFromFiles map.
     *
     * @param array $classNameFromFiles
     * @return array
     */
    protected function resolveClassNameFromFiles(array $classNameFromFiles): array
    {
        return $classNameFromFiles;
    }

    /**
     * Resolver for discovery paths.
     *
     * @param array $discoveryPaths
     * @return array
     */
    protected function resolveDiscoveryPaths(array $discoveryPaths): array
    {
        return $discoveryPaths;
    }

    /**
     * Resolver for the success callback.
     *
     * @param callable|null $successCallback
     * @return callable
     */
    protected function resolveSuccessCallback(callable $successCallback = null): callable
    {
        if ($successCallback === null) {
            $successCallback = function ($className, $filePath) {
                return $this->autoloadFile($className, $filePath);
            };
        }

        return $successCallback;
    }

    /**
     * Resolver for excluded classes.
     *
     * @param array $excludedClasses
     * @return array
     */
    protected function resolveExcludedClasses(array $excludedClasses): array
    {
        return $excludedClasses;
    }

    /**
     * Hook called after autoload has been executed, allowing post-processing.
     *
     * @param array    $result
     * @param array    $classNameFromFiles
     * @param array    $discoveryPaths
     * @param callable $successCallback
     * @param array    $excludedClasses
     *
     * @return array
     */
    protected function afterAutoload(
        array $result,
        array $classNameFromFiles,
        array $discoveryPaths,
        callable $successCallback,
        array $excludedClasses
    ): array {
        return $result;
    }

    /**
     * Discover files in directory paths
     *
     * @param array|string $directoryPath
     * @param array $files
     * @return array
     */
    public function discover(array|string $directoryPath, array $files = []): array
    {
        return $this->getServicesAutoloader()->discover($directoryPath, $files);
    }

    /**
     * Deduct default discovery paths from roots
     *
     * @param array $discoveryPathsRoots
     * @param string $discoverFolderSuffix
     * @return array
     */
    protected function deductDefaultDiscoveryPaths(array $discoveryPathsRoots, string $discoverFolderSuffix): array
    {
        return $this->getServicesAutoloader()->deductDefaultDiscoveryPaths($discoveryPathsRoots, $discoverFolderSuffix);
    }

    /**
     * Autoload a single file
     *
     * @param string $className
     * @param string $filePath
     * @return object
     */
    protected function autoloadFile(string $className, string $filePath): object
    {
        $manager = property_exists($this, 'manager') && $this->manager instanceof ManagerInterface ? $this->manager : null;
        return $this->getServicesAutoloader()->autoloadFile($className, $filePath, $manager);
    }
}
