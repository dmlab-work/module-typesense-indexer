<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Test-only stand-in for core C3, loaded when the installed core predates it.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Synonym;

class SynonymSetManager
{
    /**
     * @param string $name
     * @param array<int,array{id:string,synonyms:string[]}> $items
     */
    public function upsert(string $name, array $items): void
    {
    }

    /**
     * @param string $name
     */
    public function delete(string $name): void
    {
    }

    /**
     * @param string $name
     */
    public function exists(string $name): bool
    {
        return false;
    }
}
