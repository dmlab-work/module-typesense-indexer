<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model;

use MageDevGroup\TypesenseCore\Model\Schema\ReconcilePolicy;
use MageDevGroup\TypesenseIndexer\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The scope-config double is stubbed for reads in most cases and asserted on in a couple.
#[AllowMockObjectsWithoutExpectations]
class ConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var Config */
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    /**
     * Stub the scope config with a path => value map; unlisted paths read as null.
     *
     * @param array<string,mixed> $values
     */
    private function stubConfig(array $values): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static fn(string $path) => $values[$path] ?? null);
    }

    public function testUsesNativeInvalidationReadsTheEscapeHatchFlag(): void
    {
        $this->scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_USE_NATIVE_INVALIDATION)
            ->willReturn(true);

        self::assertTrue($this->config->usesNativeInvalidation());
    }

    public function testUsesNativeInvalidationIsFalseWhenTheFlagIsUnset(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with(Config::XML_PATH_USE_NATIVE_INVALIDATION)
            ->willReturn(false);

        self::assertFalse($this->config->usesNativeInvalidation());
    }

    public function testReconcilePolicyReadsThresholdAndOverride(): void
    {
        $this->stubConfig([
            Config::XML_PATH_REBUILD_THRESHOLD => '25000',
            Config::XML_PATH_DECISION_OVERRIDE => 'REBUILD',
        ]);

        $policy = $this->config->getReconcilePolicy();

        self::assertSame(25000, $policy->getRebuildThreshold());
        self::assertSame(ReconcilePolicy::REBUILD, $policy->getDecisionOverride());
    }

    public function testReconcilePolicyFallsBackToDefaults(): void
    {
        $this->stubConfig([]);

        $policy = $this->config->getReconcilePolicy();

        self::assertSame(500000, $policy->getRebuildThreshold());
        self::assertSame(ReconcilePolicy::AUTO, $policy->getDecisionOverride());
    }

    public function testReconcilePolicyKeepsAZeroThresholdWhichDisablesTheSizeRule(): void
    {
        $this->stubConfig([Config::XML_PATH_REBUILD_THRESHOLD => '0']);

        self::assertSame(0, $this->config->getReconcilePolicy()->getRebuildThreshold());
    }

    public function testReconcilePolicyRejectsAnUnknownOverrideAsAuto(): void
    {
        $this->stubConfig([Config::XML_PATH_DECISION_OVERRIDE => 'maybe']);

        self::assertSame(ReconcilePolicy::AUTO, $this->config->getReconcilePolicy()->getDecisionOverride());
    }

    public function testReconcilePolicyFallsBackWhenThresholdIsNonNumeric(): void
    {
        $this->stubConfig([Config::XML_PATH_REBUILD_THRESHOLD => 'many']);

        self::assertSame(500000, $this->config->getReconcilePolicy()->getRebuildThreshold());
    }
}
