<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Api;

/**
 * The search-engine identifier this suite registers into Magento's engine list.
 *
 * Published so `typesense-search` (which performs the engine registration) and this
 * module's plugins reference the string from exactly one place — a stable contract,
 * not an internal `Model\` detail.
 *
 * @api
 */
class EngineCode
{
    /**
     * Value stored in `catalog/search/engine` when Typesense is the active engine.
     */
    public const ENGINE = 'typesense';
}
