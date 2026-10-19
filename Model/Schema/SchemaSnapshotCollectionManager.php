<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema;

use DmLab\TypesenseCore\Model\Collection\CollectionManager;

/**
 * A collection manager whose `get()` returns a pinned schema, so the reconciler diffs the same
 * snapshot {@see CollectionSchemaSync} built the desired set from. Every other call is live.
 */
class SchemaSnapshotCollectionManager extends CollectionManager
{
    /**
     * @var array<string,array<mixed>> collection name ⇒ pinned schema
     */
    private array $pinned = [];

    /**
     * Serve `$schema` for `$name` until {@see unpin()}.
     *
     * @param string $name
     * @param array<mixed> $schema
     */
    public function pin(string $name, array $schema): void
    {
        $this->pinned[$name] = $schema;
    }

    /**
     * Resume live reads for `$name`.
     *
     * @param string $name
     */
    public function unpin(string $name): void
    {
        unset($this->pinned[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): array
    {
        return $this->pinned[$name] ?? parent::get($name);
    }
}
