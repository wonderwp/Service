<?php

namespace WonderWp\Component\Service;

use WonderWp\Component\PluginSkeleton\AbstractManager;
use WonderWp\Component\PluginSkeleton\ManagerAwareInterface;
use WonderWp\Component\PluginSkeleton\ManagerAwareTrait;

abstract class AbstractService implements ServiceInterface, ManagerAwareInterface
{
    use ManagerAwareTrait;

    /**
     * AbstractService constructor.
     *
     * @param AbstractManager $manager
     */
    public function __construct(AbstractManager $manager = null)
    {
        $this->manager = $manager;
    }

}
