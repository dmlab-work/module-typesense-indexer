# Changelog

## 0.2.1

- Fixed: saving or deleting a search synonym no longer invalidates `catalogsearch_fulltext` on Typesense (synonyms sync in place).
- Fixed: attribute saves via repository/API no longer always invalidate `catalogsearch_fulltext` as newly storefront-exposed.
- Tested on Magento 2.4.9 and PHP 8.5.

## 0.2.0

- Composite products carry their in-stock children's option values.
- Booleans and options typed as int (fixes the layered-nav 500).
- Regex `price_[0-9]+_.*` / `position_category_.*` fields.
- Prices from `min_price`.
- Option labels searchable, `sort_<code>` label sort fields, HTML stripped.
- Public storefront collection `<alias>_public` (labels of storefront-visible attributes only, thumbnail falls back to small/base image); `IndexNameResolverInterface::getPublicAliasName()` added (implementers must add it).
- Native synonyms synced to Typesense.
- Stacked partial reindex with batched deletes.
- New or deleted attributes no longer force a full reindex.
- Orphan collection prune and store-delete cleanup (also on website/store group delete).
- Turning `is_filterable` / `used_for_sort_by` off now removes the facet/sort in place.
- MSI `is_out_of_stock`.
- Elasticsearch dependency removed.
- Requires core `^0.2.0`. A full reindex is required after upgrade.

## 0.1.1

- Requires the sibling Typesense modules `^0.1.1`.

## 0.1.0

- First release under the `dmlab` vendor and `DmLab` namespace.
