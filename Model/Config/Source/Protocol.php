<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Config\Source;

use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Transport protocols the Typesense client can speak.
 */
class Protocol implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return array_map(
            static fn (string $protocol): array => [
                'value' => $protocol,
                'label' => __(strtoupper($protocol)),
            ],
            ConnectionSettings::ALLOWED_PROTOCOLS
        );
    }
}
