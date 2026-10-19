# DmLab_TypesenseIndexer

[![CI](https://github.com/dmlab-work/module-typesense-indexer/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-typesense-indexer/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-typesense-indexer/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-typesense-indexer) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-typesense-indexer)](https://packagist.org/packages/dmlab/module-typesense-indexer) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-typesense-indexer)](https://packagist.org/packages/dmlab/module-typesense-indexer) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-typesense-indexer/php)](https://packagist.org/packages/dmlab/module-typesense-indexer) [![License](https://img.shields.io/packagist/l/dmlab/module-typesense-indexer)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

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
makes a facet appear with no reindex — the flagship free feature. Creating or deleting an attribute
doesn't reindex either: the schema is reconciled in place. Only when a PATCH is unsafe or over
`rebuild_threshold` does the module fall back to native invalidation.

What a document carries:

- **Composite products** (configurable, bundle, grouped) carry the union of their own and their
  enabled children's option values, so a configurable matches `color`/`size` filters. Out-of-stock
  children are skipped unless *Display Out of Stock Products* is on.
- **Types**: booleans are `int32` 0/1; select/multiselect are always `int32[]` option ids; every
  value, declared or not, is cast to its policy type.
- **Labels**: option attributes also store `<code>_value` (`string[]`, store labels), the field that
  searchable options are queried by, and `sort_<code>` (first label) when `used_for_sort_by = 1`.
  Searchable text has HTML stripped.
- **Prices and positions** use regex fields `price_[0-9]+_.*` (`price_<group>_<website>`, from
  `min_price`) and `position_category_.*`, so a new customer group, website or category needs no
  schema change.
- **Stock**: `is_in_stock` (legacy stock status) and `is_out_of_stock` (the website's MSI salability
  when MSI is enabled, else legacy). The public collection's `is_in_stock` is derived from the latter.

Escape hatch: *DMLab → Typesense → Catalog Indexing → Reindex on Attribute Change = Yes*
(`dmlab_typesense/indexer/use_native_invalidation`, default No) disables the PATCH path for attribute
create, delete and flag changes and restores native invalidation.

## Reindex model

Full reindex builds a *fresh* collection per store view, imports documents in batches, and **swaps
the alias onto it as the final step** — zero-downtime. Consumers query the alias
`typesense_<indexerId>_<storeId>` (e.g. `typesense_catalogsearch_fulltext_1`); the physical
collection behind it is versioned and swapped atomically.

- **Partial reindex** is stacked: changed products are upserted first, then only the ids that were
  not rebuilt are deleted (batched `filter_by=id:[…]`), so products never vanish mid-update.
- **Orphan prune**: after each swap, older versions of the same alias (e.g. left by a killed
  rebuild) are dropped; newer ones are kept as another process's pending build.
- **Store delete** (directly or with its website/store group) drops the store's aliases, collections
  and synonym set.

## Public collection

Each store also gets `<alias>_public` for direct browser queries with a search-only key. The main
collection holds every customer group's price, so it must never be exposed. The public one holds
only `name`, `sku`, `short_description`, option labels (`<code>_value`) of storefront-exposed
attributes (searchable, filterable, visible on front, used in listing or sorting), `visibility`, `status`,
`is_in_stock`, `price` (NOT LOGGED IN group), `thumbnail` (falling back to `small_image`, then `image`)
and the stored `url_key`, `tax_class_id`. No facets.
Making a non-exposed option attribute exposed invalidates `catalogsearch_fulltext` (its labels need a reindex). It follows the main collection's lifecycle (rebuild + swap, partial, delete). Resolve its
name with `IndexNameResolverInterface::getPublicAliasName()`.

## Synonyms

Native synonym groups (*Marketing → SEO & Search → Search Synonyms*) are synced to the Typesense
synonym set `<alias>_synonyms` per store: after every full rebuild and on each group save/delete.

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

Register a provider by merging into the matching composite's `providers` array in `di.xml`. Document
providers run in array order (`attribute` → `children` → `labels` → …); register after `labels` to see
composite-merged option ids and their labels.

## Requirements

Magento 2.4.9 · PHP 8.3–8.5 · `dmlab/module-typesense-core ^0.2.0`.

## Tests

Integration tests run against a real Typesense and skip unless `TYPESENSE_TEST_URL` and
`TYPESENSE_TEST_API_KEY` are set: `vendor/bin/phpunit -c Test/Integration/phpunit.xml`.

## License

OSL-3.0 © DMLab.
