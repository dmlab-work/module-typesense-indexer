# DmLab_TypesenseIndexer

Turns the Magento catalog into Typesense documents and owns the **attribute → schema policy**
(L1 of the Typesense suite). It implements Magento's indexer contracts
(`IndexStructureInterface`, `SaveHandler\IndexerInterface`), so the standard
`catalogsearch_fulltext` indexer writes to Typesense instead of Elasticsearch/OpenSearch.

It is the **single writer** of catalog collections — `typesense-search` and any future read model
consume the same documents without forking the pipeline. Depends on `dmlab/module-typesense-core`.

## Install

```bash
composer require dmlab/module-typesense-indexer
bin/magento module:enable DmLab_TypesenseIndexer
bin/magento setup:upgrade
```

Add `dmlab/module-typesense-search` to select `typesense` as the search engine and query it.

## Configuration

This module **owns the Typesense configuration**; core is pure transport and consumes it through
`TypesenseCore\Api\ConnectionSettingsInterface`.

- **Connection** — *Stores → Configuration → Catalog → Catalog Search*, shown when the engine is
  `typesense` (`catalog/search/typesense_*`: host, port, index prefix, API key, protocol, additional
  nodes, timeouts, retry, health-cache TTL). The API key is encrypted and `sensitive`.
- **Schema policy** — *DMLab → Typesense → Schema Changes* (`rebuild_threshold` default
  500000, `decision_override` default `auto`).
- The `dmlab` tab, `dmlab_typesense` section and `DmLab_TypesenseIndexer::config`
  ACL resource are defined here; other suite modules only add their own groups.

## FAT documents, THIN schema — why flag changes are cheap

Two facts about Typesense drive the design: a document stores its full JSON **on disk** including
undeclared keys (only declared fields cost RAM), and a schema `PATCH` **rebuilds the index from the
stored documents — no reimport**.

So: **write every attribute value into the document regardless of its flags** (FAT), but **declare
only the fields the flags require** (THIN). Native Magento invalidates the whole fulltext index when
a search flag changes — hours on a large catalog. Because the value is already in every document,
this module instead does an **in-place schema PATCH** (≈0.3s on 50k docs). Toggling `is_filterable`
makes a facet appear with no reindex — the flagship free feature. A genuinely *new* attribute still
reindexes (its values are in no document yet); if an in-place PATCH is unsafe or over threshold, the
module falls back to native invalidation rather than leave the schema stale.

Escape hatch: *DMLab → Typesense → Catalog Indexing → Reindex on Attribute Change = Yes*
(`dmlab_typesense/indexer/use_native_invalidation`, default No) disables the PATCH path.

## Reindex model

Reindex builds a *fresh* collection per store view, imports documents in batches, and **swaps the
alias onto it as the final step** — zero-downtime. Consumers query the alias
`typesense_<indexerId>_<storeId>` (e.g. `typesense_catalogsearch_fulltext_1`); the physical
collection behind it is versioned and swapped atomically.

## Extension points

Everything is contributed through di-merged composites — nobody writes a document or PATCHes a
collection directly, and the schema is computed (`base + Σ providers`), so additions cannot collide.

- **`Model/Schema/FieldProviderInterface`** — `getFields(int $storeId): FieldSpec[]`, adds schema
  fields (the seam `typesense-semantic` uses for vector fields via `FieldSpec::extra`).
- **`Model/Document/DocumentDataProviderInterface`** — `addData(array $documents, int $storeId)`,
  adds document data; providers chain. Data may be written with no schema field declaring it (FAT).
- **`Api/SearchableFieldsProviderInterface`** — `get(int $storeId): array` returns field ⇒ query
  weight, the single source of truth `typesense-search` and `typesense-instant-search` map onto
  `query_by` / their browser config. The query side can never drift from the indexed side.

Register a provider by merging into the matching composite's `providers` array in `di.xml`.

## Requirements

Magento 2.4.x · PHP 8.3–8.5 · `dmlab/module-typesense-core`.

## License

OSL-3.0 © DMLab.
