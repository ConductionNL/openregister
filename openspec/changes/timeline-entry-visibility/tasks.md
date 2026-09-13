# Tasks: timeline-entry-visibility

## 1. Notes

- [ ] 1.1 `visibility` on note create, update and read in `NoteService`, stored in the comment's reference metadata; default and read-time fallback `internal`.
- [ ] 1.2 `update` guard on setting it; audit entry on the object when it changes.

## 2. Feed

- [ ] 2.1 `visibility` on every merged row in `ActivityProvider`; `visibility` query parameter; enforced public view for callers without `update`.
- [ ] 2.2 Chip on the notes leaf, toggle for users with `update`, filter chip on the feed.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/timeline-visibility.spec.ts`: write two notes, make one public, read the feed as a reader and as a handler.
- [ ] 3.2 Unit tests for defaults, the guard, the audit entry and the enforced filter.
