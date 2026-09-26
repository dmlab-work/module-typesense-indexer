<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\Elasticsearch;

use DmLab\TypesenseIndexer\Api\EngineCode;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResourceModel;
use Magento\Elasticsearch\Model\Indexer\Fulltext\Plugin\Category\Product\Attribute as NativeEsAttributePlugin;
use Magento\Framework\Search\EngineResolverInterface;

/**
 * Neutralises the native Elasticsearch attribute plugin when Typesense is the active engine.
 *
 * That plugin ({@see NativeEsAttributePlugin}) is gated on `Config::isElasticsearchEnabled()`, which
 * is `in_array(currentEngine, engineList)`. Because `typesense-search` must register `typesense` into
 * that engine list (opting out breaks the stock→fulltext indexer dependency), the gate is **true** for
 * us — and the plugin then throws a `LocalizedException` when the created handler is not an
 * `ElasticsearchIndexerHandler`, which it never is under Typesense. Result: creating **any** new
 * product attribute fatals.
 *
 * We `around` its `afterSave` and, when the current engine is Typesense, return `$result` untouched
 * without ever entering the native body. `disabled="true"` is deliberately avoided — it would also
 * break stores that switch back to ES/OpenSearch.
 */
class AttributeIndexerNeutralizePlugin
{
    /**
     * @param EngineResolverInterface $engineResolver the currently configured search engine
     */
    public function __construct(
        private readonly EngineResolverInterface $engineResolver
    ) {
    }

    /**
     * Short-circuit the native ES attribute plugin when Typesense is active.
     *
     * @param NativeEsAttributePlugin $subject the wrapped native ES plugin
     * @param \Closure $proceed the native `afterSave`
     * @param AttributeResourceModel $attributeResource the native plugin's subject
     * @param AttributeResourceModel $result the native plugin's return value (the resource model)
     * @return AttributeResourceModel
     */
    public function aroundAfterSave(
        NativeEsAttributePlugin $subject,
        \Closure $proceed,
        AttributeResourceModel $attributeResource,
        AttributeResourceModel $result
    ): AttributeResourceModel {
        if ($this->engineResolver->getCurrentSearchEngine() === EngineCode::ENGINE) {
            return $result;
        }

        return $proceed($attributeResource, $result);
    }
}
