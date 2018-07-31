<?php

namespace WonderWp\Component\Service;

use WonderWp\Component\DependencyInjection\Container;
use WonderWp\Component\DependencyInjection\ContainerAwareInterface;
use WonderWp\Component\DependencyInjection\ContainerAwareTrait;
use WonderWp\Component\PluginSkeleton\AbstractManager;

abstract class AbstractContainerAwareService extends AbstractService implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @inheritdoc
     * @codeCoverageIgnore
     */
    public function __construct(AbstractManager $manager = null)
    {
        parent::__construct($manager);
        $this->setContainer(Container::getInstance());
    }
}
