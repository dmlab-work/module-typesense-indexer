<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Schema;

/**
 * A di-merged aggregate of {@see FieldProviderInterface}.
 *
 * It only concatenates — deduplication and ordering are the {@see DesiredSchemaBuilder}'s
 * job, so a provider can override an earlier one's field and the builder resolves it.
 */
class CompositeFieldProvider implements FieldProviderInterface
{
    /**
     * @param FieldProviderInterface[] $providers di-merged, order is the merge order
     */
    public function __construct(
        private readonly array $providers = []
    ) {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof FieldProviderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'A field provider must implement %s, got %s.',
                    FieldProviderInterface::class,
                    get_debug_type($provider)
                ));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getFields(int $storeId): array
    {
        $fields = [];
        foreach ($this->providers as $provider) {
            $fields[] = $provider->getFields($storeId);
        }

        return array_merge([], ...$fields);
    }
}
