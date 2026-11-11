# Tasks: object-read-state

Built in this order because each step is the ground the next one stands on: the
row first, then what invalidates it, then the query that reads it, then the
surfaces, then the bell.

## 1. The row

- [x] 1.1 `lib/Db/ObjectReadState.php`: one row per (user, object), carrying the
      moment the user last saw it and a per-sub-resource seen map.
- [x] 1.2 `lib/Db/ObjectReadStateMapper.php`: find one, mark seen, mark unread,
      the uuid set for a user, and the purge by object.
- [x] 1.3 `lib/Migration/Version1Date20260914140000.php`: the table, add-only,
      with the unique index on `(user_id, object_uuid)`.
- [x] 1.4 Bump `appinfo/info.xml` above every stamp on `development` and on
      every open release branch, so gate 110 sees the migration ship.

## 2. What makes an object unread again

- [x] 2.1 `lib/Service/Interaction/SubstantiveChangeEvaluator.php`: read
      `x-openregister-read-state` off the schema and answer whether one write
      was substantive. Undeclared means any non-computed property.
- [x] 2.2 `lib/Service/Interaction/ReadStateService.php`: the one place that
      decides who may read and write a read state, marks an object seen, marks
      it back to unread, invalidates it for everyone but the actor, and counts
      the unread sub-resources.
- [x] 2.3 `lib/Listener/ReadStateInvalidationListener.php` on `ObjectUpdatedEvent`.
- [x] 2.4 `lib/Listener/ReadStatePruneListener.php` on `ObjectDeletedEvent`.
- [x] 2.5 Register both listeners in `lib/AppInfo/Application.php`.

## 3. Unread as a query axis

- [x] 3.1 `SearchQueryHandler`: `_unread=true` resolves the caller and hands the
      mapper a uid, never a post-filter. An anonymous caller gets an honest
      empty page.
- [x] 3.2 `MagicSearchHandler`: a correlated `NOT EXISTS` against the read-state
      table inside `buildFilteredQuery()`, so the page, the total and the facets
      all see the same restriction. Reserve `_unread` and `_unreadFor`.

## 4. The surfaces

- [x] 4.1 `ObjectEntity`: the transient `unread` flag and `unreadCounts` map.
- [x] 4.2 `RenderObject`: attach both, memoised per request, never per row.
- [x] 4.3 `lib/Controller/ObjectReadStateController.php`: mark read, mark
      unread, and mark a sub-resource read. A user's own state only.
- [x] 4.4 Routes in `appinfo/routes.php`.

## 5. The bell

- [x] 5.1 Migration: `subject_type`, `subject_id`, `snoozed_until` and
      `archived_at` on `openregister_notification_history`, add-only.
- [x] 5.2 `NotificationHistory` and its mapper: the four fields, the snooze and
      archive writes, the subject filter, and the thread read.
- [x] 5.3 `lib/Service/Notification/NotificationClearingService.php`: opening
      the work clears the bell, and a notification whose subject is gone is
      archived rather than left unread.
- [x] 5.4 `NotificationHistoryController`: snooze, archive, mark a thread read,
      and the subject-type axis on the list.
- [x] 5.5 Routes for the four verbs.

## 6. Verification

- [x] 6.1 PHPUnit: a user's own state only, the marker advances on a
      substantive change, the list carries the marker in one query.
- [x] 6.2 The mutation: the marker never advances, shown red.
- [x] 6.3 Playwright under `tests/e2e/ci/`, one anchor per spec scenario that is
      not excluded.
- [x] 6.4 `docs/` note on the annotation.
