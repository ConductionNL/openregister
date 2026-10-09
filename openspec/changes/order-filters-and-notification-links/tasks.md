# Tasks: order-filters-and-notification-links

## 1. Ordering by a translatable property

- [x] 1.1 `MagicSearchHandler::applySorting()` orders a property marked `translatable: true` by the value of its language map in the resolved language chain, falling back to any value and to the plain text of a row that is not a map. Verify: `tests/Unit/Db/MagicSearchHandlerTranslatableSortTest.php` runs the generated ORDER BY against SQLite with mixed rows (plain strings and language maps) and asserts one alphabetical order; it fails on the old code.
- [x] 1.2 The language chain mirrors rendering: the accepted request languages the register offers, then the register's languages, then its default language. Verify: the same test orders by English when English is accepted.
- [x] 1.3 Live: on :8099 store one dossiq case type title as a plain string and confirm `_order={"title":"asc"}` returns one alphabetical list.

## 2. Header filters on OpenRegister's own tables

- [x] 2.1 `src/services/listFilter.js`: apply a `filter-change` payload to an active-filter map, and filter rows by that map (`[like]`, `[gte]`, `[lte]`, exact). Verify: `src/services/listFilter.spec.js`.
- [x] 2.2 `SchemasIndex.vue` and `RegistersIndex.vue` listen for `filter-change`, pass `activeFilters`, filter before sorting and paging, and go back to page 1 on a change. The registers list sorts from its headers. Verify: live on :8099.

## 3. Notification link

- [x] 3.1 `AnnotationNotifier::prepare()` sets the notification link to the owning app's detail page through the deep link registry, else OpenRegister's object view. The implicit View action uses the same link. Verify: `tests/Unit/Notification/AnnotationNotifierLinkTest.php` fails on the old code.
- [x] 3.2 A declared action whose url is a path is made absolute before `setLink()`. Verify: the same test.
- [x] 3.3 Actions are added as parsed actions, so the notifications API returns them. Verify: live on :8099, a pipelinq client update notification carries the link and a View action (before: `actions: []`).

## 4. Aggregation eviction

- [x] 4.1 `AggregationCacheInvalidationListener` resolves the object's register and schema ids to slugs before `evictForSchema()`, so eviction hits the key `AggregationCache` writes. Verify: `tests/Unit/Listener/AggregationCacheEvictionBySlugTest.php` (real AggregationCache) fails on the old code; live on :8099 the leadProduct count is no longer served stale after a write.
