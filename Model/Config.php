<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model;

use MageDevGroup\TypesenseCore\Model\Schema\ReconcilePolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reader for this module's own `magedevgroup_typesense/*` configuration.
 *
 * The connection (`catalog/search/typesense_*`) is read by {@see ConnectionSettings};
 * this reader owns the indexer escape hatch and the schema-reconcile policy — the values
 * core's provider-agnostic {@see ReconcilePolicy} is built from.
 */
class Config
{
    /**
     * When set, attribute saves fall back to Magento's native reindex-on-change semantics instead
     * of the in-place schema PATCH — the escape hatch for a site that wants stock behaviour.
     */
    public const XML_PATH_USE_NATIVE_INVALIDATION = 'magedevgroup_typesense/indexer/use_native_invalidation';

    /** Document count above which a schema change is answered with `needs-rebuild`. */
    public const XML_PATH_REBUILD_THRESHOLD = 'magedevgroup_typesense/schema/rebuild_threshold';

    /** Forces the reconciler's decision regardless of size and type compatibility. */
    public const XML_PATH_DECISION_OVERRIDE = 'magedevgroup_typesense/schema/decision_override';

    /** Decisions the admin source model offers; the values match core's {@see ReconcilePolicy} constants. */
    public const ALLOWED_DECISIONS = [
        ReconcilePolicy::AUTO,
        ReconcilePolicy::IN_PLACE,
        ReconcilePolicy::REBUILD,
    ];

    private const DEFAULT_REBUILD_THRESHOLD = 500000;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether attribute saves should invalidate the fulltext index the native way rather than PATCH.
     */
    public function usesNativeInvalidation(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_USE_NATIVE_INVALIDATION);
    }

    /**
     * The reconcile policy core's reconciler is handed, from the configured threshold and override.
     *
     * An unknown decision override falls back to `auto` rather than fatal an admin save.
     */
    public function getReconcilePolicy(): ReconcilePolicy
    {
        return new ReconcilePolicy($this->getRebuildThreshold(), $this->getDecisionOverride());
    }

    /**
     * Document count above which a schema change must be a rebuild, not an in-place PATCH.
     *
     * 0 disables the size rule. A non-numeric value falls back to the default.
     */
    private function getRebuildThreshold(): int
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_REBUILD_THRESHOLD);
        $raw = is_scalar($raw) ? trim((string)$raw) : '';
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return self::DEFAULT_REBUILD_THRESHOLD;
        }

        return (int)$raw;
    }

    /**
     * Forced reconciler decision, one of core's {@see ReconcilePolicy} constants; `auto` otherwise.
     */
    private function getDecisionOverride(): string
    {
        $decision = $this->scopeConfig->getValue(self::XML_PATH_DECISION_OVERRIDE);
        $decision = is_scalar($decision) ? strtolower(trim((string)$decision)) : '';

        return in_array($decision, self::ALLOWED_DECISIONS, true) ? $decision : ReconcilePolicy::AUTO;
    }
}
