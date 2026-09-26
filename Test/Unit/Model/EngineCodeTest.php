<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model;

use DmLab\TypesenseIndexer\Api\EngineCode;
use PHPUnit\Framework\TestCase;

class EngineCodeTest extends TestCase
{
    public function testEngineConstantIsTypesense(): void
    {
        self::assertSame('typesense', EngineCode::ENGINE);
    }
}
