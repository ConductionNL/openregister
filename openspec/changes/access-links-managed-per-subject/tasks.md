# Tasks: access-links-managed-per-subject

## 1. Listing per subject

- [x] 1.1 `GET /api/access-links` with `subjectType` and `subjectId` lists every link on the subject for a caller the mint guard admits, and answers 404 to anybody else (REQ-ABL-005). Verify: `AccessLinkControllerTest`.
- [x] 1.2 A link somebody else minted is listed without its anchor; the caller's own carries the anchor, `url` and `pageUrl` (REQ-ABL-005). Verify: `AccessLinkServiceTest`.

## 2. Revoking per subject

- [x] 2.1 `DELETE /api/access-links/{id}` with the link's subject revokes a link somebody else minted when the mint guard admits the caller; a link on another subject, or a caller the guard refuses, answers 404 (REQ-ABL-005). Verify: `AccessLinkControllerTest`, `AccessLinkServiceTest`.

## 3. Verification

- [x] 3.1 Newman: a manager lists and revokes a member's link on a shared view on a live instance (planninq's `Public Share` folder covers the consumer side).
  > 2026-11-25: `tests/integration/openregister-access-links-per-subject.postman_collection.json`, against a sandbox
  > Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e), with the CI users `e2e-owner` (manager, owns the views) and
  > `e2e-other` (member). The member mints a read link over the manager's shared view A; the manager lists it with
  > its creator and use count and without its anchor; a revoke naming view B is 404 and the link stays live; the
  > revoke naming A is 200 and the link then answers 404. On the manager's private view C the member's list, mint and
  > revoke of the manager's link all answer 404 and that link stays live. The run found that the publish check let a
  > view through that the caller cannot read in their own organisation (the views endpoint answers 404 on it); this
  > PR gives the check the views endpoint's own read rule. 23 requests, 28 assertions, 0 failed with it; 4 fail
  > without it.
