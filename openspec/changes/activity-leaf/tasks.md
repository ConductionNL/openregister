# Tasks: activity-leaf

## 1. Merge

- [x] 1.1 Five-source merge with a shared cursor.
      `lib/Service/Integration/ActivityFeedMerge.php` decides order, bounds
      and the cursor with no database in sight;
      `lib/Service/Integration/ActivityFeedService.php` fetches what
      OpenRegister owns (its own audit trail) and asks the Activity provider
      for its rows. The three sources whose owners are other leaves — files,
      notes and mail — are handed IN by the caller that already holds them,
      because a service reaching into three other apps' tables would be three
      integrations nobody declared, each breaking silently on an instance
      without that app.
      **The cursor is a TIME, not an offset**: five sources with five offsets
      cannot be paged, and rows appear twice or not at all as soon as the
      sources are unequal.
      **Each source is bounded to one page** before the merge, so the feed
      never costs an unbounded read of five tables to render twenty rows.
- [x] 1.2 Read entries excluded unless requested. The exclusion is the
      DEFAULT rather than a chip that starts off: fifteen of seventeen rows
      on the measured case detail were reads. Only an AUDIT row can be a read,
      so a note whose action happens to be spelled `read` is still shown.
      Reads are fetched and filtered after, so the toggle brings them back
      without a second, differently shaped query.
      Per-user toggle MEMORY is the surface's and waits on 2.1.

## 2. Surfaces

- [ ] 2.1 `tab` and `widget` surfaces with kind chips and a date range.
      **This is a nextcloud-vue change, not an openregister one**, and that
      is why it is not cheap here: the leaf surfaces come from the library
      (`registerLeafIntegrations`, `CnActivityTab`), and openregister's
      `src/integrations/bootstrap.js` registers what the library ships into
      the shared registry rather than declaring surfaces of its own. The
      engine already accepts `kinds`, `from` and `until` and returns a count
      per kind, so a chip can render "0" rather than vanish; what is missing
      is the library's surface and the per-user memory of the reads toggle.
- [x] 2.2 CSV export of the filtered feed:
      `lib/Service/Integration/ActivityFeedExport.php`. It exports the page
      it is GIVEN and re-queries nothing, because an export that re-reads can
      disagree with the screen and the reader cannot tell which was wrong.
      Cells a spreadsheet would execute (`=`, `+`, `-`, `@`) are written as
      text, and an undated row exports an empty cell rather than 1970.
      **PDF is not built**: `ExportService::exportToPdf()` renders objects of
      a register and schema, not an arbitrary row set, so a feed PDF is a new
      renderer rather than a call, and it belongs beside the surface that
      decides what a printed feed looks like (2.1).

## 3. Tests

- [x] 3.1 Unit tests for the merge order, the bound and the read toggle:
      `tests/Unit/Service/Integration/ActivityFeedMergeTest.php` (14) and
      `ActivityFeedServiceTest.php` (7). They cover the tie-break, the
      undated row sorting last, paging that loses and repeats nothing, the
      per-source bound and its ceiling, a source that could not be read being
      NAMED rather than merged as nothing, and a summary that names changed
      fields and never their values.
- [ ] 3.2 `tests/e2e/ci/activity-leaf.spec.ts`: waits on 2.1, because there
      is no surface to open yet.

## Who enforces access to this feed

Nobody, here, and that is deliberate rather than an omission. Every row is
about one object and carries no rights of its own; whether the caller may see
that object is decided by the object read that got them to the leaf, which is
`MagicRbacHandler`'s business. **On a schema that configures no
authorization, that read is open to every authenticated account** — the
handler says so in its own comment — so a feed mounted on such an object is
readable by everyone who can reach the page. Closing that is the consuming
schema's job (a private scope, or an authorization chain); a check added in
this service would be a second answer to a question the platform already
answers, and the two would drift.
