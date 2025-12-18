<?php

use WonderWp\Component\DependencyInjection\Container;
use WonderWp\Component\Service\Services\ServicesAutoloaderService;

add_action('wonderwp.loader.load', 'wwp_register_service_definitions_towards_container', 10, 2);
add_action('wwp.cache.clear', 'wwp_clear_discovery_cache', 10, 2);

function wwp_clear_discovery_cache()
{
    $servicesAutoloader = Container::getInstance()->offsetGet('wwp.service.servicesAutoloader');
    $servicesAutoloader->clearDiscoveryCache();
}

function wwp_register_service_definitions_towards_container(Container $container)
{
    $container['wwp.service.servicesAutoloader'] = function () {
        return new ServicesAutoloaderService();
    };
}