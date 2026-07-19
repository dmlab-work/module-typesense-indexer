<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Plugin\CatalogSearch;

use MageDevGroup\TypesenseIndexer\Model\Plugin\AttributeSaveContext;
use MageDevGroup\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use MageDevGroup\TypesenseIndexer\Model\Plugin\CatalogSearch\AttributeSchemaPatchPlugin;
use MageDevGroup\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResourceModel;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Plugin\Attribute as NativeAttributePlugin;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttributeSchemaPatchPluginTest extends TestCase
{
    /**
     * A stub by default; a test that verifies delegation escalates it to a mock (createMock).
     *
     * @var AttributeSaveHandler
     */
    private AttributeSaveHandler $handler;

    /** @var AttributeSaveContext */
    private AttributeSaveContext $saveContext;

    /** @var AttributeFieldPolicy */
    private AttributeFieldPolicy $policy;

    /** @var NativeAttributePlugin */
    private NativeAttributePlugin $nativePlugin;

    /** @var AttributeResourceModel */
    private AttributeResourceModel $attributeResource;

    /** @var AttributeResourceModel */
    private AttributeResourceModel $result;

    protected function setUp(): void
    {
        $this->handler = $this->createStub(AttributeSaveHandler::class);
        // A real context so the before → after state relay is exercised end to end.
        $this->saveContext = new AttributeSaveContext();
        $this->policy = $this->createStub(AttributeFieldPolicy::class);
        $this->nativePlugin = $this->createStub(NativeAttributePlugin::class);
        $this->attributeResource = $this->createStub(AttributeResourceModel::class);
        $this->result = $this->createStub(AttributeResourceModel::class);
    }

    /**
     * Escalate the handler to a mock that reports the given active state, for call verification.
     */
    private function handlerMock(bool $isActive): AttributeSaveHandler&MockObject
    {
        $handler = $this->createMock(AttributeSaveHandler::class);
        $handler->method('isActive')->willReturn($isActive);
        $this->handler = $handler;

        return $handler;
    }

    private function plugin(): AttributeSchemaPatchPlugin
    {
        return new AttributeSchemaPatchPlugin($this->handler, $this->saveContext, $this->policy);
    }

    /**
     * @param bool $isNew whether the attribute reports itself as new
     * @param bool $schemaChanged what the policy reports about this save's schema-relevant flags
     * @param bool $configChanged what the policy reports about this save's search-config flags
     */
    private function attribute(
        bool $isNew,
        bool $schemaChanged = false,
        bool $configChanged = false
    ): AbstractAttribute {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('isObjectNew')->willReturn($isNew);
        $this->policy->method('schemaFlagsChanged')->willReturn($schemaChanged);
        $this->policy->method('searchConfigChanged')->willReturn($configChanged);

        return $attribute;
    }

    /**
     * The native afterSave, asserted never to run when we take over.
     */
    private function failingProceed(): \Closure
    {
        return function (): AttributeResourceModel {
            self::fail('The native afterSave (invalidate) must not run when the Typesense flow is active.');
        };
    }

    public function testFlagChangePatchesInPlaceAndSkipsNativeInvalidation(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleExistingAttributeSave');
        $handler->expects(self::never())->method('handleNewAttributeSave');

        $plugin = $this->plugin();
        $plugin->beforeBeforeSave($this->nativePlugin, $this->attributeResource, $this->attribute(false, true));
        $returned = $plugin->aroundAfterSave(
            $this->nativePlugin,
            $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );

        self::assertSame($this->result, $returned);
    }

    public function testExistingSaveWithNoSchemaFlagChangeIsANoOp(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::never())->method('handleExistingAttributeSave');
        $handler->expects(self::never())->method('handleNewAttributeSave');
        $handler->expects(self::never())->method('refreshSearchConfig');

        $plugin = $this->plugin();
        $plugin->beforeBeforeSave($this->nativePlugin, $this->attributeResource, $this->attribute(false, false));
        $returned = $plugin->aroundAfterSave(
            $this->nativePlugin,
            $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );

        self::assertSame($this->result, $returned);
    }

    public function testExistingSaveWithOnlyASearchConfigFlagRefreshesConfigWithoutReconcile(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('refreshSearchConfig');
        $handler->expects(self::never())->method('handleExistingAttributeSave');
        $handler->expects(self::never())->method('handleNewAttributeSave');

        $plugin = $this->plugin();
        $plugin->beforeBeforeSave(
            $this->nativePlugin,
            $this->attributeResource,
            $this->attribute(false, false, true)
        );
        $returned = $plugin->aroundAfterSave(
            $this->nativePlugin,
            $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );

        self::assertSame($this->result, $returned);
    }

    public function testNewAttributeInvalidatesAndSkipsReconcile(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleNewAttributeSave');
        $handler->expects(self::never())->method('handleExistingAttributeSave');

        $plugin = $this->plugin();
        $plugin->beforeBeforeSave($this->nativePlugin, $this->attributeResource, $this->attribute(true));
        $returned = $plugin->aroundAfterSave(
            $this->nativePlugin,
            $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );

        self::assertSame($this->result, $returned);
    }

    public function testInactiveEngineDefersToNativeAfterSave(): void
    {
        $handler = $this->handlerMock(false);
        $handler->expects(self::never())->method('handleExistingAttributeSave');
        $handler->expects(self::never())->method('handleNewAttributeSave');

        $native = $this->createStub(AttributeResourceModel::class);
        $proceedRan = false;
        $proceed = function () use (&$proceedRan, $native): AttributeResourceModel {
            $proceedRan = true;

            return $native;
        };

        $plugin = $this->plugin();
        $plugin->beforeBeforeSave($this->nativePlugin, $this->attributeResource, $this->attribute(false));
        $returned = $plugin->aroundAfterSave($this->nativePlugin, $proceed, $this->attributeResource, $this->result);

        self::assertTrue($proceedRan, 'The native afterSave must run when the Typesense flow is inactive.');
        self::assertSame($native, $returned);
    }

    public function testDeletionInvalidationIsLeftToTheUntouchedNativeAfterDelete(): void
    {
        // A removed attribute still invalidates — through the native afterDelete, which we deliberately
        // do not wrap. Guard that this plugin intercepts only the save path.
        $methods = get_class_methods(AttributeSchemaPatchPlugin::class);

        foreach ($methods as $method) {
            self::assertStringNotContainsStringIgnoringCase(
                'delete',
                $method,
                'The schema-patch plugin must not intercept the attribute delete path.'
            );
        }
    }
}
