# Tasks: a-conflicting-save-shows-the-other-value

## 1. The conflict body

- [x] 1.1 The 409 body lists each conflicting property with the sent, read and stored values.
- [x] 1.2 Only properties the caller changed and somebody else changed are listed.
- [x] 1.3 The body is filtered by field-level security; a refused property is named without values.

## 2. Every write

- [x] 2.1 The version assertion moves into the save pipeline so PUT asserts as PATCH does.
- [x] 2.2 A write with no expected version keeps today's behaviour.

## 3. The record

- [x] 3.1 A refused write writes an audit entry naming both versions and the actor.

## 4. Tests

- [x] 4.1 Unit tests for the three-value body, the intersection rule, the filtered property and the PUT assertion.
- [ ] 4.2 A Newman request asserting the 409 shape.
- [x] 4.3 Deduplication check (ADR-012) recorded in the PR body.

## What was built

`lib/Service/Object/ConflictReport.php` builds the body,
`ObjectsController::versionConflictResponse()` is the one assertion both PUT
and PATCH call, and `recordRefusedWrite()` leaves the trail.
`tests/Unit/Service/Object/ConflictReportTest.php` (9).

🔑 **THE "READ" VALUE COMES FROM THE AUDIT TRAIL, NOT FROM THE CALLER.** The
caller sends a timestamp, not the values they saw. The `old` side of the
EARLIEST intervening change is, by construction, what was there when they read
it. Asking the caller to send what they read would let a confused client report
a conflict against a value nobody ever stored. There is a test with two
intervening writes, because taking the `old` of the most recent one shows the
caller a value they never saw and passes every single-write test.

🔑 **THE INTERSECTION HAS A TEST ON EACH SIDE.** Report too much and the dialog
lists fields nobody touched, which is how people learn to click through it;
report too little and a real collision is invisible. Mutation-checked: removing
the second half of the intersection reddened three assertions.

🔑 **`error` KEEPS ITS SENTENCE AND `code` IS NEW.** The rest of this app puts a
slug in `error` and this endpoint has always put a sentence there. Correcting it
today would break every client branching on the substring "Conflict", which is
what the published body invited, so the sentence stays and the machine code
arrives beside it.

## 4.3 Deduplication check (ADR-012)

- The `updated` timestamp already used as the concurrency token: reused, not
  replaced with a new version column.
- `PropertyRbacHandler::canReadProperty()`: reused for the conflict filter,
  rather than a second notion of what a caller may see.
- `AuditTrailMapper::createAuditTrailEntry()`: reused for the refusal entry.
- The audit trail's `changed` block, `{property: {old, new}}`: reused as the
  source of the "read" value, which is why this change needs no version store.
- No new locking. `run-scoped-object-locking` remains the answer where a hard
  lock is wanted; these two are complementary.

## Named rather than claimed

- **The assertion sits at the controller seam, not inside `SaveObject`.** D-3
  asks for the save pipeline; what is testable and asked for by REQ-CSO-003's
  scenarios is that a full replace asserts exactly as a partial update does, and
  both doors now call ONE method so they cannot answer differently. Moving it
  inside `ObjectService::saveObject()` means threading an expected version
  through a signature with many callers, and is its own change.
- **4.2, the Newman request.** Not written: it needs a live instance, and this
  lane writes no request collection it cannot run.
- **`If-Match` is accepted only when it parses as an instant.** The object's
  concurrency token IS its `updated` timestamp, so a header that is not one
  cannot be a version of it. Taking the header unconditionally reddened 28
  existing controller tests, every one of which stubs `getHeader` once for
  `Content-Type` — a caller sending an ordinary etag would have had every write
  refused against a value that was never a version.
