# Integration: Activity

## Problem

NC Activity provides a user-facing feed of system events (file uploads, shares, comments, etc.). This is different from OR's audit trail (which is immutable, object-scoped, admin-facing). Case handlers want to see "what happened recently around this case" — a blended activity view on the object.

## Context

- **Backend:** greenfield — wrap NC Activity API (filter by object-associated actors/events)
- **Required NC app:** `activity`
- **Storage:** query-time filter (no link table — events are transient)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`ActivityFeedService` + `ActivityController` + `ActivityProvider` + `CnActivityTab` + `CnActivityCard`. Unlike most integrations, this has no link table — activity is queried per-render based on the object's relationships (linked files, linked tasks, etc.). `getStorageStrategy()` returns `'query-time'` (new enum value).

## Scope

**In scope:** Query-time storage strategy (new value), provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Modifying NC Activity itself; custom activity types; filtered subscriptions.

## Acceptance criteria

- [ ] Activity tab shows relevant activity events for the object and its linked entities
- [ ] Blended feed combines NC Activity stream with OR's cross-integration events
- [ ] Widget on dashboards shows "N new activities today"
- [ ] Reference-property `referenceType: 'activity'` works (though niche — activity events aren't typically referenced)
- [ ] Parity gate passes; nl+en done
