---
kind: code
depends_on: []
---

# Proposal: order-filters-and-notification-links

## Summary

Three things a person met on cloud.conduction.nl on 8 October 2026 (round 3 of
the pipelinq review) that OpenRegister owns:

1. **Ordering by a translatable property gave two sorted runs.** dossiq's case
   types, ordered by title, listed College to Toezichtzaak Milieu and then
   Cultuursubsidie to Woo-verzoek. A translatable property is stored as a
   language map (`{"nl": "Woo-verzoek"}`) on rows saved since translations
   arrived, and as a plain string on older rows. The query ordered by the raw
   column text, so every plain row sorted before every map row. The order now
   reads the value a person sees: the language the response resolves to, then
   the register's languages, then any value. A plain row sorts by its own text.
2. **OpenRegister's own tables sorted but did not filter.** The schemas and
   registers lists offer a filter in every column header that can filter, and
   applying one narrows the list. The registers list also sorts from its
   headers now; its sortable headers did nothing when clicked.
3. **A notification had no link.** pipelinq's "Client changed" notification
   arrived with `link: ""`, so clicking it went nowhere. Every object
   notification now links to the owning app's detail page through the deep
   link registry, with OpenRegister's object view as the fallback. The implicit
   View action uses the same link. A declared action whose deep link came back
   as a path is made absolute, because Nextcloud refuses a relative action
   link and the notification then failed to render. Actions are added as
   parsed actions: Nextcloud's notification API returns only those, which is
   why the cloud check saw `actions: []` on every OpenRegister notification.

## Why

- Item 1: a list ordered by title must read as one alphabetical list. The
  split depends on when a row was last saved, which a person cannot see.
- Item 2: Ruben decided that every column header sorts and filters, fleet
  wide. nextcloud-vue 2.71 shows header filters on a host-fed table only when
  the page listens for `filter-change`; the registers page passed a schema, so
  its header filters showed and did nothing.
- Item 3: the deep link registry spec already says notification links should
  use the registry (deep-link-registry, "Notification deep links SHALL use the
  deep link registry"). The activity stream does since #4445; notifications
  never set a link at all.

## Scope

- `MagicSearchHandler::applySorting()` orders a translatable property by its
  resolved value on PostgreSQL, MySQL/MariaDB and SQLite. Non-translatable
  properties and metadata columns keep their bare column.
- `SchemasIndex.vue` and `RegistersIndex.vue` listen for `filter-change`,
  hold the active header filters and filter the loaded list on the client
  (`[like]` contains, `[gte]`/`[lte]` ranges, exact values).
- `AnnotationNotifier::prepare()` sets the notification link.

Out of scope: the cross-schema UNION search orders on the raw column as
before (a translatable column there is rare and the order keys are shared with
a PHP merge). The other OpenRegister index pages (sources, applications,
organisations, audit trail, search) are left for a follow-up.
