<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Config\Source;

use DmLab\TypesenseIndexer\Model\Config\Source\Protocol;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use PHPUnit\Framework\TestCase;

class ProtocolTest extends TestCase
{
    /** @var Protocol */
    private Protocol $source;

    protected function setUp(): void
    {
        $this->source = new Protocol();
    }

    public function testEveryOfferedValueIsOneConnectionSettingsAccepts(): void
    {
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertSame(ConnectionSettings::ALLOWED_PROTOCOLS, $values);
    }

    public function testEachOptionIsLabelled(): void
    {
        foreach ($this->source->toOptionArray() as $option) {
            $this->assertSame(strtoupper($option['value']), (string)$option['label']);
        }
    }
}
