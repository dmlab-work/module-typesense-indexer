<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\CatalogSearch;

use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveContext;
use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use DmLab\TypesenseIndexer\Model\Plugin\PersistedAttributeFlags;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResourceModel;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Plugin\Attribute as NativeAttributePlugin;
use Magento\Framework\Model\AbstractModel;

/**
 * Turns an attribute-flag change into a cheap schema PATCH instead of a full catalog reindex —
 * the module's flagship feature.
 *
 * It wraps the **native** `CatalogSearch` attribute plugin rather than the resource model, because
 * that plugin calls `invalidate()` from its own private state: no plugin can suppress another
 * plugin's internal call, and `disabled="true"` would silently break stores on ES/OpenSearch. So we
 * `around` its `afterSave` and, when the Typesense flow is active, take over: skip the native
 * invalidation and reconcile the schema in place instead — while still performing its other duties
 * (reset the search-request config, mark the searchable list stale) via {@see AttributeSaveHandler}.
 *
 * A **new** attribute has no product values yet, so declaring its fields is a PATCH too; a deleted one
 * is dropped by the same PATCH. Neither invalidates unless core answers `needs-rebuild`. Save state is
 * captured in the before phase ({@see AttributeSaveContext}) because `afterSave` never receives the attribute.
 *
 * @see AttributeSaveHandler for the active/inactive gate and the reconcile-or-invalidate logic.
 */
class AttributeSchemaPatchPlugin
{
    /**
     * @param AttributeSaveHandler $handler the active gate and reconcile-or-invalidate logic
     * @param AttributeSaveContext $saveContext relays the captured state from the before phase to the after phase
     * @param AttributeFieldPolicy $policy owns which flags actually affect the schema
     * @param PersistedAttributeFlags $persistedFlags the flags stored before this save
     */
    public function __construct(
        private readonly AttributeSaveHandler $handler,
        private readonly AttributeSaveContext $saveContext,
        private readonly AttributeFieldPolicy $policy,
        private readonly PersistedAttributeFlags $persistedFlags
    ) {
    }

    /**
     * Capture what the after phase needs before the attribute is saved — `afterSave` cannot see it:
     * whether the attribute is new, whether it touches the schema (a new one: any schema flag set;
     * an existing one: a schema flag changed) and whether its labels just became public. Compared
     * against the stored flags, read here before the save overwrites them.
     *
     * @param NativeAttributePlugin $subject the wrapped native plugin
     * @param AttributeResourceModel $attributeResource the save target (the native plugin's subject)
     * @param AbstractModel $attribute the attribute being saved
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeBeforeSave(
        NativeAttributePlugin $subject,
        AttributeResourceModel $attributeResource,
        AbstractModel $attribute
    ): void {
        $isNew = $attribute->isObjectNew();
        $before = $isNew ? [] : $this->persistedFlags->get($attribute);
        $this->saveContext->push(
            $isNew,
            $isNew ? $this->policy->schemaFlagsSet($attribute) : $this->policy->schemaFlagsChanged($attribute, $before),
            $this->policy->searchConfigChanged($attribute, $before),
            !$isNew && $this->policy->optionLabelsBecameExposed($attribute, $before)
        );
    }

    /**
     * When the Typesense flow is active, replace the native invalidation with an in-place PATCH.
     *
     * The state captured in {@see beforeBeforeSave} is always consumed to keep the stack balanced,
     * regardless of which branch handles the save. A save that touches no schema-relevant flag skips
     * the per-store reconcile round-trips; a new attribute, or a change of a search-config flag only
     * (`is_visible_in_advanced_search`), still refreshes the request config, matching native. An option
     * attribute that just became storefront-exposed invalidates the index (its public labels need a reindex),
     * even under the escape hatch, since native does not gate on every exposure flag.
     *
     * @param NativeAttributePlugin $subject the wrapped native plugin
     * @param \Closure $proceed the native `afterSave`
     * @param AttributeResourceModel $attributeResource the native plugin's subject
     * @param AttributeResourceModel $result the native plugin's return value (the resource model)
     * @return AttributeResourceModel
     */
    public function aroundAfterSave(
        NativeAttributePlugin $subject,
        \Closure $proceed,
        AttributeResourceModel $attributeResource,
        AttributeResourceModel $result
    ): AttributeResourceModel {
        $state = $this->saveContext->pop();

        if ($state['exposeLabels']) {
            $this->handler->handleLabelsExposed();
        }

        if (!$this->handler->isActive()) {
            return $proceed($attributeResource, $result);
        }

        if ($state['reconcile']) {
            $this->handler->handleAttributeSave();
        } elseif ($state['isNew'] || $state['refreshConfig']) {
            $this->handler->refreshSearchConfig();
        }

        return $result;
    }

    /**
     * When the Typesense flow is active, replace the native delete invalidation with an in-place PATCH.
     *
     * The PATCH drops the deleted attribute's fields.
     *
     * @param NativeAttributePlugin $subject the wrapped native plugin
     * @param \Closure $proceed the native `afterDelete`
     * @param AttributeResourceModel $attributeResource the native plugin's subject
     * @param AttributeResourceModel $result the native plugin's return value (the resource model)
     * @return AttributeResourceModel
     */
    public function aroundAfterDelete(
        NativeAttributePlugin $subject,
        \Closure $proceed,
        AttributeResourceModel $attributeResource,
        AttributeResourceModel $result
    ): AttributeResourceModel {
        if (!$this->handler->isActive()) {
            return $proceed($attributeResource, $result);
        }

        $this->handler->handleAttributeDelete();

        return $result;
    }
}
