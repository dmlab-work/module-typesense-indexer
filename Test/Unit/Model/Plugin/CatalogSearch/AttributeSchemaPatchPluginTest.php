<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\CatalogSearch;

use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveContext;
use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use DmLab\TypesenseIndexer\Model\Plugin\CatalogSearch\AttributeSchemaPatchPlugin;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResourceModel;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Plugin\Attribute as NativeAttributePlugin;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as CatalogAttribute;
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
     * @param bool $schemaSet what the policy reports about any schema-relevant flag being set
     */
    private function attribute(
        bool $isNew,
        bool $schemaChanged = false,
        bool $configChanged = false,
        bool $schemaSet = false
    ): AbstractAttribute {
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('isObjectNew')->willReturn($isNew);
        $this->policy->method('schemaFlagsChanged')->willReturn($schemaChanged);
        $this->policy->method('searchConfigChanged')->willReturn($configChanged);
        $this->policy->method('schemaFlagsSet')->willReturn($schemaSet);

        return $attribute;
    }

    /**
     * The native afterSave, asserted never to run when we take over.
     */
    private function failingProceed(): \Closure
    {
        return function (): AttributeResourceModel {
            self::fail('The native after-plugin (invalidate) must not run when the Typesense flow is active.');
        };
    }

    private function save(AbstractAttribute $attribute, ?\Closure $proceed = null): AttributeResourceModel
    {
        $plugin = $this->plugin();
        $plugin->beforeBeforeSave($this->nativePlugin, $this->attributeResource, $attribute);

        return $plugin->aroundAfterSave(
            $this->nativePlugin,
            $proceed ?? $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );
    }

    public function testFlagChangePatchesInPlaceAndSkipsNativeInvalidation(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleAttributeSave');

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
        $handler->expects(self::never())->method('handleAttributeSave');
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
        $handler->expects(self::never())->method('handleAttributeSave');

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

    public function testNewFlaggedAttributeReconcilesWithoutInvalidating(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleAttributeSave');
        $handler->expects(self::never())->method('refreshSearchConfig');

        self::assertSame($this->result, $this->save($this->attribute(true, true, true, true)));
    }

    public function testNewUnflaggedAttributeOnlyRefreshesConfig(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('refreshSearchConfig');
        $handler->expects(self::never())->method('handleAttributeSave');

        // A new object reports every flag as changed; only a set flag counts.
        self::assertSame($this->result, $this->save($this->attribute(true, true, false, false)));
    }

    public function testUsedForSortByChangeOnAnOptionAttributeReconciles(): void
    {
        $this->policy = new AttributeFieldPolicy($this->createStub(FieldNameResolver::class));
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('isObjectNew')->willReturn(false);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('dataHasChangedFor')->willReturnCallback(
            static fn (string $flag): bool => $flag === 'used_for_sort_by'
        );

        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleAttributeSave');

        $this->save($attribute);
    }

    public function testInactiveEngineDefersToNativeAfterSave(): void
    {
        $handler = $this->handlerMock(false);
        $handler->expects(self::never())->method('handleAttributeSave');

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

    public function testActiveDeleteReconcilesAndSkipsNativeInvalidation(): void
    {
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleAttributeDelete');

        $returned = $this->plugin()->aroundAfterDelete(
            $this->nativePlugin,
            $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );

        self::assertSame($this->result, $returned);
    }

    public function testInactiveDeleteDefersToNativeAfterDelete(): void
    {
        $handler = $this->handlerMock(false);
        $handler->expects(self::never())->method('handleAttributeDelete');

        $native = $this->createStub(AttributeResourceModel::class);
        $proceed = static fn (): AttributeResourceModel => $native;

        $returned = $this->plugin()->aroundAfterDelete(
            $this->nativePlugin,
            $proceed,
            $this->attributeResource,
            $this->result
        );

        self::assertSame($native, $returned);
    }

    /**
     * A real catalog attribute (select, int) with the given original and current data.
     *
     * @param array<string,mixed> $orig
     * @param array<string,mixed> $data
     */
    private function catalogSelect(array $orig, array $data, bool $isNew = false): CatalogAttribute
    {
        $data += $orig;
        $attribute = $this->createStub(CatalogAttribute::class);
        $attribute->method('isObjectNew')->willReturn($isNew);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('getOrigData')->willReturnCallback(static fn (string $key): mixed => $orig[$key] ?? null);
        $attribute->method('getData')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);
        $attribute->method('dataHasChangedFor')->willReturnCallback(
            static fn (string $key): bool => ($data[$key] ?? null) != ($orig[$key] ?? null)
        );

        return $attribute;
    }

    public function testOptionAttributeBecomingExposedInvalidatesForItsLabels(): void
    {
        $this->policy = new AttributeFieldPolicy($this->createStub(FieldNameResolver::class));
        $handler = $this->handlerMock(true);
        $handler->expects(self::once())->method('handleLabelsExposed');
        $handler->expects(self::never())->method('handleAttributeSave');

        $this->save($this->catalogSelect(['used_in_product_listing' => 0], ['used_in_product_listing' => 1]));
    }

    public function testOptionAttributeBecomingExposedInvalidatesUnderTheEscapeHatch(): void
    {
        $this->policy = new AttributeFieldPolicy($this->createStub(FieldNameResolver::class));
        $handler = $this->handlerMock(false);
        $handler->expects(self::once())->method('handleLabelsExposed');

        $this->save(
            $this->catalogSelect(['is_visible_on_front' => 0], ['is_visible_on_front' => 1]),
            fn (): AttributeResourceModel => $this->result
        );
    }

    public function testTogglingBetweenExposedStatesOnlyPatches(): void
    {
        $this->policy = new AttributeFieldPolicy($this->createStub(FieldNameResolver::class));
        $handler = $this->handlerMock(true);
        $handler->expects(self::never())->method('handleLabelsExposed');
        $handler->expects(self::once())->method('handleAttributeSave');

        $this->save($this->catalogSelect(
            ['is_visible_on_front' => 1, 'is_filterable' => 0],
            ['is_visible_on_front' => 0, 'is_filterable' => 1]
        ));
    }

    public function testNewExposedAttributeDoesNotInvalidate(): void
    {
        $this->policy = new AttributeFieldPolicy($this->createStub(FieldNameResolver::class));
        $handler = $this->handlerMock(true);
        $handler->expects(self::never())->method('handleLabelsExposed');

        $this->save($this->catalogSelect([], ['is_searchable' => 1], true));
    }
}
