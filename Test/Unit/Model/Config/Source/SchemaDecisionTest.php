<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Config\Source;

use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseIndexer\Model\Config\Source\SchemaDecision;
use PHPUnit\Framework\TestCase;

class SchemaDecisionTest extends TestCase
{
    /** @var SchemaDecision */
    private SchemaDecision $source;

    protected function setUp(): void
    {
        $this->source = new SchemaDecision();
    }

    public function testOffersEveryReconcilePolicyDecision(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertSame(
            [ReconcilePolicy::AUTO, ReconcilePolicy::IN_PLACE, ReconcilePolicy::REBUILD],
            $values
        );
    }

    public function testEachOptionIsLabelled(): void
    {
        foreach ($this->source->toOptionArray() as $option) {
            $this->assertNotSame('', (string)$option['label']);
        }
    }
}
