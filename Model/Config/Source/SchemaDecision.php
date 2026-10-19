<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Config\Source;

use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
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
