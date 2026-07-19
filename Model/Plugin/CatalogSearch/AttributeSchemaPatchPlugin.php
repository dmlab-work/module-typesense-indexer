<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Plugin\CatalogSearch;

use MageDevGroup\TypesenseIndexer\Model\Plugin\AttributeSaveContext;
use MageDevGroup\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use MageDevGroup\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
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
 * A **new** attribute is the exception: its column is in no document, so we invalidate to write it.
 * Newness is captured in the before phase ({@see AttributeSaveContext}) because `afterSave` never
 * receives the attribute.
 *
 * @see AttributeSaveHandler for the active/inactive gate and the reconcile-or-invalidate logic.
 */
class AttributeSchemaPatchPlugin
{
    /**
     * @param AttributeSaveHandler $handler the active gate and reconcile-or-invalidate logic
     * @param AttributeSaveContext $saveContext relays the captured state from the before phase to the after phase
     * @param AttributeFieldPolicy $policy owns which flags actually affect the schema
     */
    public function __construct(
        private readonly AttributeSaveHandler $handler,
        private readonly AttributeSaveContext $saveContext,
        private readonly AttributeFieldPolicy $policy
    ) {
    }

    /**
     * Capture what the after phase needs before the attribute is saved — `afterSave` cannot see it:
     * whether the attribute is new, and (for an existing one) whether a schema-relevant flag changed.
     * Both must be read here while the attribute and its dirty-field state are still available.
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
        $this->saveContext->push(
            $attribute->isObjectNew(),
            $this->policy->schemaFlagsChanged($attribute),
            $this->policy->searchConfigChanged($attribute)
        );
    }

    /**
     * When the Typesense flow is active, replace the native invalidation with an in-place PATCH.
     *
     * The state captured in {@see beforeBeforeSave} is always consumed to keep the stack balanced,
     * regardless of which branch handles the save. An existing save that changed no schema-relevant
     * flag (a label, a default value, sort order) is a no-op — skipping it avoids the per-store
     * reconcile round-trips the native plugin would also skip. A save that changed only a search-config
     * flag (`is_visible_in_advanced_search`) still refreshes the request config, matching native.
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

        if (!$this->handler->isActive()) {
            return $proceed($attributeResource, $result);
        }

        if ($state['isNew']) {
            $this->handler->handleNewAttributeSave();
        } elseif ($state['reconcile']) {
            $this->handler->handleExistingAttributeSave();
        } elseif ($state['refreshConfig']) {
            $this->handler->refreshSearchConfig();
        }

        return $result;
    }
}
