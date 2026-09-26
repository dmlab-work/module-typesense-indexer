<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseIndexer\Model\Indexer\StoreScopeResolver;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Search\Request\Dimension;
use PHPUnit\Framework\TestCase;

class StoreScopeResolverTest extends TestCase
{
    public function testResolvesTheCanonicalStoreIdFromTheFirstDimension(): void
    {
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getId')->willReturn(3);

        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->expects(self::once())
            ->method('getScope')
            ->with('3')
            ->willReturn($scope);

        $resolver = new StoreScopeResolver($scopeResolver);

        self::assertSame(3, $resolver->resolve([new Dimension('scope', '3')]));
    }

    public function testThrowsWhenNoDimensionIsPresent(): void
    {
        $resolver = new StoreScopeResolver($this->createStub(ScopeResolverInterface::class));

        $this->expectException(LocalizedException::class);

        $resolver->resolve([]);
    }
}
