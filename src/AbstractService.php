<?php

namespace WonderWp\Component\Service;

use WonderWp\Component\PluginSkeleton\AbstractManager;

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

}
