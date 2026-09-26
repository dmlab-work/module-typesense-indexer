<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'DmLab_TypesenseIndexer',
    __DIR__
);
