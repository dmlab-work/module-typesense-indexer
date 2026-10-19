<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseCore\Model\Schema\Reconciler;
use DmLab\TypesenseCore\Model\Schema\SchemaDiffer;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\CollectionSchemaSync;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\SchemaSnapshotCollectionManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;

class CollectionSchemaSyncTest extends TestCase
{
    /**
     * @var int number of live `GET /collections/live` reads
     */
    private int $gets = 0;

    /**
     * @var array<int,array<mixed>> every PATCH body sent
     */
    private array $patches = [];

    /**
     * @var string[] lock/unlock calls interleaved with builds and PATCHes
     */
    private array $events = [];

    /**
     * An in-process lock manager: a held lock refuses a second `lock()` instead of waiting.
     */
    private function lockManager(): LockManagerInterface
    {
        return new class ($this->events) implements LockManagerInterface {
            /**
             * @var array<string,bool>
             */
            private array $held = [];

            /**
             * @param string[] $events
             */
            public function __construct(private array &$events)
            {
            }

            public function lock(string $name, int $timeout = -1): bool
            {
                if (isset($this->held[$name])) {
                    return false;
                }
                $this->events[] = 'lock ' . $name;

                return $this->held[$name] = true;
            }

            public function unlock(string $name): bool
            {
                $this->events[] = 'unlock ' . $name;
                unset($this->held[$name]);

                return true;
            }

            public function isLocked(string $name): bool
            {
                return isset($this->held[$name]);
            }
        };
    }

    /**
     * A snapshot manager over a client whose second and later reads show a concurrently materialized `price_0_2`.
     */
    private function snapshotManager(): SchemaSnapshotCollectionManager
    {
        $base = [
            ['name' => 'sku', 'type' => 'string'],
            ['name' => 'price_[0-9]+_.*', 'type' => 'float', 'facet' => true, 'sort' => true, 'optional' => true],
            ['name' => 'price_0_1', 'type' => 'float', 'facet' => true, 'sort' => true, 'optional' => true],
        ];
        $client = $this->createStub(TypesenseClient::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $path, ?array $body = null) use ($base): array {
                if ($method === 'PATCH') {
                    $this->patches[] = $body;

                    return [];
                }
                $fields = $base;
                if ($this->gets++ > 0) {
                    $fields[] = ['name' => 'price_0_2', 'type' => 'float', 'facet' => true, 'sort' => true];
                }

                return ['name' => 'live', 'num_documents' => 10, 'fields' => $fields];
            }
        );

        return new SchemaSnapshotCollectionManager($client, $this->createStub(ConnectionSettingsInterface::class));
    }

    public function testReconcilerDiffsTheSameSchemaSnapshotTheRegexInstancesCameFrom(): void
    {
        $manager = $this->snapshotManager();
        $schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $schemaBuilder->method('build')->willReturn([
            new FieldSpec('sku', 'string'),
            new FieldSpec('price_[0-9]+_.*', 'float', true, null, true, true),
            new FieldSpec('color', 'int32[]', facet: true, optional: true),
        ]);
        $sync = new CollectionSchemaSync(
            new Reconciler(new SchemaDiffer(), $manager),
            $manager,
            $schemaBuilder,
            $this->createStub(PublicSchemaBuilder::class),
            $this->lockManager()
        );

        $decision = $sync->reconcileMain('live', 1, new ReconcilePolicy(0, ReconcilePolicy::AUTO));

        self::assertFalse($decision->needsRebuild());
        self::assertSame(1, $this->gets);
        self::assertSame(
            [['name' => 'color', 'type' => 'int32[]', 'facet' => true, 'optional' => true]],
            $this->patches[0]['fields']
        );

        $manager->get('live');
        self::assertSame(2, $this->gets, 'the pin is released after the reconcile');
    }

    public function testPinIsReleasedWhenTheReconcileFails(): void
    {
        $manager = $this->snapshotManager();
        $reconciler = $this->createStub(Reconciler::class);
        $reconciler->method('reconcile')->willThrowException(new \RuntimeException('boom'));
        $sync = new CollectionSchemaSync(
            $reconciler,
            $manager,
            $this->createStub(DesiredSchemaBuilder::class),
            $this->createStub(PublicSchemaBuilder::class),
            $this->lockManager()
        );

        try {
            $sync->reconcilePublic('live', 1, new ReconcilePolicy(0, ReconcilePolicy::AUTO));
            self::fail('expected the reconcile failure to propagate');
        } catch (\RuntimeException) {
        }

        $manager->get('live');
        self::assertSame(2, $this->gets);
        self::assertSame(
            ['lock dmlab_typesense_schema_live', 'unlock dmlab_typesense_schema_live'],
            $this->events,
            'the lock is released when the reconcile fails'
        );
    }

    public function testDesiredBuildThroughPatchRunsUnderTheCollectionLock(): void
    {
        $manager = $this->snapshotManager();
        $schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $schemaBuilder->method('build')->willReturnCallback(function (): array {
            $this->events[] = 'build';

            return [new FieldSpec('sku', 'string'), new FieldSpec('color', 'int32[]', facet: true, optional: true)];
        });
        $reconciler = new Reconciler(new SchemaDiffer(), $manager);
        $sync = new CollectionSchemaSync(
            $reconciler,
            $manager,
            $schemaBuilder,
            $this->createStub(PublicSchemaBuilder::class),
            $this->lockManager()
        );

        $sync->reconcileMain('live', 1, new ReconcilePolicy(0, ReconcilePolicy::AUTO));

        self::assertSame(
            ['lock dmlab_typesense_schema_live', 'build', 'build', 'unlock dmlab_typesense_schema_live'],
            $this->events
        );
        self::assertCount(1, $this->patches);
    }

    public function testLockTimeoutThrowsWithoutBuildingOrPatching(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $schemaBuilder = $this->createMock(DesiredSchemaBuilder::class);
        $schemaBuilder->expects(self::never())->method('build');
        $manager = $this->snapshotManager();
        $sync = new CollectionSchemaSync(
            new Reconciler(new SchemaDiffer(), $manager),
            $manager,
            $schemaBuilder,
            $this->createStub(PublicSchemaBuilder::class),
            $lockManager
        );

        $this->expectException(TypesenseException::class);
        $sync->reconcileMain('live', 1, new ReconcilePolicy(0, ReconcilePolicy::AUTO));
    }

    public function testAttributeSaveWhoseReconcileTimedOutIsAppliedByTheLockHolder(): void
    {
        $fields = [['name' => 'sku', 'type' => 'string']];
        $client = $this->createStub(TypesenseClient::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $path, ?array $body = null) use (&$fields): array {
                if ($method === 'PATCH') {
                    foreach ($body['fields'] as $field) {
                        $fields = array_values(array_filter($fields, fn (array $f) => $f['name'] !== $field['name']));
                        if (empty($field['drop'])) {
                            $fields[] = $field;
                        }
                    }

                    return [];
                }

                return ['name' => 'live', 'num_documents' => 10, 'fields' => $fields];
            }
        );
        $manager = new SchemaSnapshotCollectionManager($client, $this->createStub(ConnectionSettingsInterface::class));

        $filterable = false;
        $adminSave = null;
        $saved = false;
        $blocked = false;
        $schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $schemaBuilder->method('build')->willReturnCallback(
            function () use (&$filterable, &$adminSave, &$saved, &$blocked): array {
                $desired = [new FieldSpec('sku', 'string')];
                if ($filterable) {
                    $desired[] = new FieldSpec('color', 'int32[]', facet: true, optional: true);
                }
                if ($adminSave !== null && !$saved) {
                    // The admin save commits right after the recheck read the old flags.
                    $saved = $filterable = true;
                    try {
                        $adminSave();
                    } catch (TypesenseException) {
                        $blocked = true;
                    }
                }

                return $desired;
            }
        );
        $sync = new CollectionSchemaSync(
            new Reconciler(new SchemaDiffer(), $manager),
            $manager,
            $schemaBuilder,
            $this->createStub(PublicSchemaBuilder::class),
            $this->lockManager()
        );
        $policy = new ReconcilePolicy(0, ReconcilePolicy::AUTO);
        $adminSave = fn () => $sync->reconcileMain('live', 1, $policy);

        $sync->reconcileMain('live', 1, $policy);

        self::assertTrue($blocked, 'the admin reconcile times out on the lock');
        self::assertSame(['sku', 'color'], array_column($fields, 'name'));
    }

    public function testDesiredSetThatKeepsChangingStopsAfterMaxPassesAndNeedsARebuild(): void
    {
        $builds = 0;
        $schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $schemaBuilder->method('build')->willReturnCallback(function () use (&$builds): array {
            return [new FieldSpec('f' . ($builds++), 'string', optional: true)];
        });
        $manager = $this->snapshotManager();
        $sync = new CollectionSchemaSync(
            new Reconciler(new SchemaDiffer(), $manager),
            $manager,
            $schemaBuilder,
            $this->createStub(PublicSchemaBuilder::class),
            $this->lockManager()
        );

        $decision = $sync->reconcileMain('live', 1, new ReconcilePolicy(0, ReconcilePolicy::AUTO));

        self::assertCount(5, $this->patches);
        self::assertSame(6, $builds);
        self::assertTrue($decision->needsRebuild(), 'the last committed desired set was never applied');
        self::assertStringContainsString('still changing', (string)$decision->getReason());
    }

    public function testUnpinnedCollectionsAreReadLive(): void
    {
        $manager = $this->snapshotManager();
        $manager->pin('other', ['fields' => []]);

        self::assertSame(['fields' => []], $manager->get('other'));
        $manager->get('live');
        $manager->unpin('other');
        $manager->get('other');
        self::assertSame(2, $this->gets);
    }
}
