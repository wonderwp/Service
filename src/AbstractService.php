<?php

namespace WonderWp\Component\Service;

use WonderWp\Component\PluginSkeleton\AbstractManager;
use WonderWp\Component\PluginSkeleton\ManagerAwareInterface;
use WonderWp\Component\PluginSkeleton\Service\RegistrableInterface;

abstract class AbstractService implements ServiceInterface
{
    /**
     * @var AbstractManager
     */
    protected $manager;

    /**
     * AbstractService constructor.
     *
     * @param AbstractManager $manager
     */
    public function __construct(AbstractManager $manager = null) { $this->manager = $manager; }

    /**
     * @codeCoverageIgnore
     * @return AbstractManager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @codeCoverageIgnore
     *
     * @param AbstractManager $manager
     *
     * @return static
     */
    public function setManager($manager)
    {
        $this->manager = $manager;

        return $this;
    }


    /**
     * @param array $classNameFromFiles
     * @param array $discoveryPaths
     * @param callable|null $successCallback
     * @return array
     * You can take the result of the autoload method and pass it back via the $classNameFromFiles parameter to speed up the process
     */
    public function autoload(array $classNameFromFiles = [], array $discoveryPaths = [], callable $successCallback = null): array
    {
        $includedFiles = get_included_files();

        if (is_null($successCallback)) {
            $successCallback = function ($className, $filePath) {
                $this->autoloadFile($className, $filePath);
            };
        }

        if(!empty($discoveryPaths)) {
            $filesToAutoLoad = $this->discover($discoveryPaths);

            if (!empty($filesToAutoLoad)) {
                foreach ($filesToAutoLoad as $filePath) {
                    if (is_file($filePath)) {
                        if (!file_exists($filePath)) {
                            continue;
                        }

                        if(!in_array($filePath, $includedFiles)) {
                            $included = include_once $filePath;

                            if (!isset($classNameFromFiles[$filePath])) {
                                //get the class from the file
                                $classNameFromFiles[$filePath] = $this->getClassNameFromFile($filePath);
                            }
                        }
                    }
                }
            }
        }

        if(!empty($classNameFromFiles)){
            foreach ($classNameFromFiles as $filePath => $className) {
                if (class_exists($className)) {
                    $successCallback($className, $filePath);
                }
            }
        }

        return $classNameFromFiles;
    }

    protected function autoloadFile(string $className, string $filePath): object
    {
        $instance = new $className();
        if($instance instanceof ManagerAwareInterface){
            $instance->setManager($this->manager);
        }
        if ($instance instanceof RegistrableInterface) {
            $instance->register();
        }
        return $instance;
    }

    /**
     * Extract the class name from a file
     * @param string $filePath
     * @return void
     */
    protected function getClassNameFromFile(string $filePath)
    {
        $content = file_get_contents($filePath);
        $namespace = $class = "";
        $tokens = token_get_all($content);
        $count = count($tokens);
        for ($i = 2; $i < $count; $i++) {
            if (isset($tokens[$i - 2][1]) && $tokens[$i - 2][1] === 'namespace') {
                $namespace = $tokens[$i][1];
            }
            if (isset($tokens[$i - 2][1]) && $tokens[$i - 2][1] === 'class') {
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
        return $files;
    }


}
