<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Config\Source;

use MageDevGroup\TypesenseCore\Model\Schema\ReconcilePolicy;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the reconciler should decide between an in-place PATCH and a rebuild.
 */
class SchemaDecision implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ReconcilePolicy::AUTO, 'label' => __('Automatic (size and type compatibility)')],
            ['value' => ReconcilePolicy::IN_PLACE, 'label' => __('Always patch in place')],
            ['value' => ReconcilePolicy::REBUILD, 'label' => __('Always rebuild')],
        ];
    }
}
