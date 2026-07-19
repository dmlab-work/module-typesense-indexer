<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Plugin;

use MageDevGroup\TypesenseIndexer\Model\Plugin\AttributeSaveContext;
use PHPUnit\Framework\TestCase;

class AttributeSaveContextTest extends TestCase
{
    public function testPopReturnsThePushedState(): void
    {
        $context = new AttributeSaveContext();
        $context->push(true, false, true);

        self::assertSame(['isNew' => true, 'reconcile' => false, 'refreshConfig' => true], $context->pop());
    }

    public function testNestedSavesPairEachPopWithItsPush(): void
    {
        $context = new AttributeSaveContext();
        $context->push(false, false, false);
        $context->push(true, true, true);

        self::assertSame(['isNew' => true, 'reconcile' => true, 'refreshConfig' => true], $context->pop());
        self::assertSame(['isNew' => false, 'reconcile' => false, 'refreshConfig' => false], $context->pop());
    }

    public function testPopWithoutAPushIsANeutralNoOpState(): void
    {
        self::assertSame(
            ['isNew' => false, 'reconcile' => false, 'refreshConfig' => false],
            (new AttributeSaveContext())->pop()
        );
    }
}
