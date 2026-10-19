<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin;

use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveContext;
use PHPUnit\Framework\TestCase;

class AttributeSaveContextTest extends TestCase
{
    public function testPopReturnsThePushedState(): void
    {
        $context = new AttributeSaveContext();
        $context->push(true, false, true, false);

        self::assertSame(
            ['isNew' => true, 'reconcile' => false, 'refreshConfig' => true, 'exposeLabels' => false],
            $context->pop()
        );
    }

    public function testNestedSavesPairEachPopWithItsPush(): void
    {
        $context = new AttributeSaveContext();
        $context->push(false, false, false, true);
        $context->push(true, true, true, false);

        self::assertSame(
            ['isNew' => true, 'reconcile' => true, 'refreshConfig' => true, 'exposeLabels' => false],
            $context->pop()
        );
        self::assertSame(
            ['isNew' => false, 'reconcile' => false, 'refreshConfig' => false, 'exposeLabels' => true],
            $context->pop()
        );
    }

    public function testPopWithoutAPushIsANeutralNoOpState(): void
    {
        self::assertSame(
            ['isNew' => false, 'reconcile' => false, 'refreshConfig' => false, 'exposeLabels' => false],
            (new AttributeSaveContext())->pop()
        );
    }
}
