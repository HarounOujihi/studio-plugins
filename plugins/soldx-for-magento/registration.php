<?php
/**
 * Soldx Integration - Magento 2 module registration
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Soldx_Integration',
    __DIR__
);
