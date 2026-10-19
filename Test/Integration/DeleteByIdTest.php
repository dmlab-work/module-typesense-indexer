<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Integration;

/**
 * The batched delete the stacked partial reindex sends removes exactly the listed ids.
 */
class DeleteByIdTest extends TypesenseTestCase
{
    public function testDeletesExactlyTheListedIds(): void
    {
        $collection = $this->createCollection('delete', $this->desiredSchema([]));
        $raw = [];
        foreach ([1, 2, 3] as $id) {
            $raw[$id] = ['sku' => 'SKU' . $id, 'status' => '1', 'visibility' => '4'];
        }
        $this->import($collection, $this->buildDocuments([], $raw));

        self::assertSame(2, $this->deleteByFilter($collection, 'id:[1,2]'));
        self::assertSame(['3'], $this->hitIds($this->searchOk($collection, [])));
    }
}
