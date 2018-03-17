<?php

namespace WonderWp\Component\Service;

use WonderWp\Component\DependencyInjection\Container;
use WonderWp\Component\DependencyInjection\ContainerAwareInterface;
use WonderWp\Component\DependencyInjection\ContainerAwareTrait;

abstract class AbstractContainerAwareService extends AbstractService implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * Constructor
     * @codeCoverageIgnore
     */
    public function __construct()
    {
        $this->setContainer(Container::getInstance());
    }
}
