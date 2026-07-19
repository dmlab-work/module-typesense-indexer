<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model;

use MageDevGroup\TypesenseIndexer\Api\EngineCode;
use PHPUnit\Framework\TestCase;

class EngineCodeTest extends TestCase
{
    public function testEngineConstantIsTypesense(): void
    {
        self::assertSame('typesense', EngineCode::ENGINE);
    }
}
