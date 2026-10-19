<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegistered(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_TypesenseIndexer', $paths);
    }

    public function testRegisteredPathPointsAtThisModule(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
        $path = $paths['DmLab_TypesenseIndexer'] ?? null;

        self::assertNotNull($path);
        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/etc/module.xml');
    }

    public function testModuleXmlDeclaresSequenceAfterCore(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertSame('DmLab_TypesenseIndexer', (string)$xml->module['name']);

        $sequence = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequence[] = (string)$module['name'];
        }

        self::assertContains('DmLab_TypesenseCore', $sequence);
        self::assertContains('Magento_CatalogSearch', $sequence);
        // The core must be sequenced first: this module's plugins and schema
        // policy consume core services, so core must load before us.
        self::assertSame('DmLab_TypesenseCore', $sequence[0]);
    }

    public function testComposerRequiresCore(): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true
        );

        self::assertSame('dmlab/module-typesense-indexer', $composer['name']);
        self::assertSame('OSL-3.0', $composer['license']);
        self::assertArrayHasKey('dmlab/module-typesense-core', $composer['require']);
        self::assertArrayHasKey('magento/module-catalog-search', $composer['require']);
    }
}
