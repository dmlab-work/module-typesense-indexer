<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit;

use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Document\Provider\AttributeDataProvider;
use DmLab\TypesenseIndexer\Model\Document\Provider\OptionLabelDataProvider;
use DmLab\TypesenseIndexer\Model\Indexer\IndexerHandler;
use DmLab\TypesenseIndexer\Model\Indexer\RunScopedCacheInterface;
use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use DmLab\TypesenseIndexer\Model\Schema\AttributeTypeMap;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackagingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const OSL_LINE = 'Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    private const REQUIRED_PACKAGES = [
        'magento/framework',
        'dmlab/module-typesense-core',
        'magento/module-backend',
        'magento/module-catalog',
        'magento/module-catalog-inventory',
        'magento/module-catalog-search',
        'magento/module-config',
        'magento/module-eav',
        'magento/module-search',
        'magento/module-store',
        'psr/log',
    ];

    public function testNoSourceFileReferencesElasticsearch(): void
    {
        $offenders = [];
        foreach (['Api', 'Model', 'etc'] as $dir) {
            $path = self::ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $source = $file->isFile() ? (string)file_get_contents($file->getPathname()) : '';
                if (str_contains($source, 'Magento\\Elasticsearch')) {
                    $offenders[] = $dir . '/' . substr($file->getPathname(), strlen($path) + 1);
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testComposerDoesNotRequireElasticsearch(): void
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertArrayNotHasKey('magento/module-elasticsearch', $composer['require']);
    }

    public function testModuleXmlDoesNotSequenceElasticsearch(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/etc/module.xml');

        self::assertNotFalse($xml);
        foreach ($xml->module->sequence->module as $module) {
            self::assertNotSame('Magento_Elasticsearch', (string)$module['name']);
        }
    }

    public function testComposerRequiresTheListedPackagesWithCaretRanges(): void
    {
        $composer = $this->composer();

        self::assertArrayHasKey('version', $composer);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $composer['version']);
        self::assertSame(
            array_merge(['php'], self::REQUIRED_PACKAGES),
            array_keys($composer['require'])
        );
        foreach (self::REQUIRED_PACKAGES as $package) {
            self::assertStringStartsWith('^', $composer['require'][$package], $package);
        }
        self::assertSame('^0.2.0', $composer['require']['dmlab/module-typesense-core']);
        self::assertArrayNotHasKey('magento/module-customer', $composer['require']);
    }

    public function testComposerSupportsMagento247AndPhp82Plus(): void
    {
        $composer = $this->composer();

        self::assertSame('~8.2.0||~8.3.0||~8.4.0||~8.5.0', $composer['require']['php']);
        self::assertSame('^103.0.7', $composer['require']['magento/framework']);
    }

    public function testComposerCarriesPublishingMetadata(): void
    {
        $composer = $this->composer();
        $repo = 'https://github.com/dmlab-work/module-typesense-indexer';

        self::assertSame($repo, $composer['homepage'] ?? null);
        self::assertSame($repo . '/issues', $composer['support']['issues'] ?? null);
        self::assertSame($repo, $composer['support']['source'] ?? null);
        self::assertNotEmpty($composer['keywords'] ?? []);
        self::assertContains('magento2', $composer['keywords']);
        self::assertArrayHasKey('dmlab/module-typesense-search', $composer['suggest'] ?? []);
    }

    public function testComposerHasNoOpenEndedConstraint(): void
    {
        $composer = $this->composer();
        $constraints = array_merge($composer['require'], $composer['require-dev'] ?? []);

        foreach ($constraints as $package => $constraint) {
            self::assertStringNotContainsString('>=', $constraint, $package);
        }
    }

    public function testModuleXmlHasNoSetupVersion(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertFalse(isset($xml->module['setup_version']));
    }

    public function testEverySourceFileCarriesTheOslLine(): void
    {
        $files = [self::ROOT . '/registration.php'];
        foreach (['Api', 'Model', 'etc'] as $dir) {
            $path = self::ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['php', 'xml'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $missing = array_values(array_filter(
            $files,
            static fn (string $file): bool => !str_contains((string)file_get_contents($file), self::OSL_LINE)
        ));

        self::assertGreaterThan(1, count($files));
        self::assertSame([], $missing);
    }

    public function testSeamsUsedByTheSearchModuleAreApiAndExtensible(): void
    {
        foreach ([ConnectionSettings::class, AttributeSaveHandler::class] as $class) {
            $reflection = new \ReflectionClass($class);

            self::assertStringContainsString('@api', (string)$reflection->getDocComment(), $class);
            self::assertFalse($reflection->isFinal(), $class);
        }
        self::assertContains('https', ConnectionSettings::ALLOWED_PROTOCOLS);
    }

    public function testGitattributesExportIgnoresOnlyPathsThePublishedRepoHas(): void
    {
        $lines = file(self::ROOT . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertSame(['/.github export-ignore', '/Test export-ignore'], $lines);
    }

    public function testEveryLocalReadmeImageExists(): void
    {
        preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', (string)file_get_contents(self::ROOT . '/README.md'), $m);
        $local = array_filter($m[1], static fn (string $src): bool => !preg_match('#^https?://#', $src));

        self::assertNotEmpty($local);
        foreach ($local as $src) {
            self::assertFileExists(self::ROOT . '/' . $src);
        }
    }

    public function testChangelogTopSectionMatchesComposerVersion(): void
    {
        preg_match('/^## (\S+)/m', (string)file_get_contents(self::ROOT . '/CHANGELOG.md'), $m);

        self::assertSame($this->composer()['version'], $m[1] ?? null);
    }

    public function testEveryRunScopedCacheIsResetByTheIndexerHandler(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/etc/di.xml');
        self::assertNotFalse($xml);
        $items = $xml->xpath(
            '//type[@name="' . IndexerHandler::class . '"]/arguments/argument[@name="runScopedCaches"]/item'
        );
        $wired = array_map(static fn (\SimpleXMLElement $item): string => trim((string)$item), $items ?: []);

        self::assertEqualsCanonicalizing(
            [
                AttributeTypeMap::class,
                OptionAttributeList::class,
                AttributeDataProvider::class,
                OptionLabelDataProvider::class,
            ],
            $wired
        );
        foreach ($wired as $class) {
            self::assertTrue(is_subclass_of($class, RunScopedCacheInterface::class), $class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . '/composer.json'), true);
        self::assertIsArray($composer);

        return $composer;
    }
}
