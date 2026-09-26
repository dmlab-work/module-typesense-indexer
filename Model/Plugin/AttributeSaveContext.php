<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin;

/**
 * Request-scoped relay for one fact the attribute-save plugin needs but cannot read where it acts.
 *
 * The native `CatalogSearch` attribute plugin captures the attribute state it needs in its
 * `beforeSave`, but its `afterSave` — the method we wrap — receives only the resource model, never
 * the attribute. So we capture what the after phase needs (is the attribute new, and did a
 * schema-relevant flag change) in the before phase and read it back in the after phase. Saves are
 * sequential (before → save → after, one attribute at a time), so a LIFO stack pairs each capture
 * with its matching read even across several saves in one request.
 */
class AttributeSaveContext
{
    /**
     * @var array<array{isNew:bool,reconcile:bool,refreshConfig:bool}> pushed in the before phase, popped in the after
     */
    private array $stack = [];

    /**
     * Record what the after phase needs about the attribute now being saved.
     *
     * @param bool $isNew whether the attribute is new (its column is in no document yet)
     * @param bool $reconcile whether a schema-relevant flag changed (an existing save that touched none is a no-op)
     * @param bool $refreshConfig whether a search-config flag changed (e.g. `is_visible_in_advanced_search`)
     */
    public function push(bool $isNew, bool $reconcile, bool $refreshConfig): void
    {
        $this->stack[] = ['isNew' => $isNew, 'reconcile' => $reconcile, 'refreshConfig' => $refreshConfig];
    }

    /**
     * Read back the state recorded for the save now completing.
     *
     * Returns a neutral no-op state when nothing was captured (a save that never passed through the
     * before phase), so the after phase does nothing rather than misattributing another save's state.
     *
     * @return array{isNew:bool,reconcile:bool,refreshConfig:bool}
     */
    public function pop(): array
    {
        return array_pop($this->stack) ?? ['isNew' => false, 'reconcile' => false, 'refreshConfig' => false];
    }
}
