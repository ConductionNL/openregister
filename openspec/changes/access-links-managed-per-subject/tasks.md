# Tasks: access-links-managed-per-subject

## 1. Listing per subject

- [x] 1.1 `GET /api/access-links` with `subjectType` and `subjectId` lists every link on the subject for a caller the mint guard admits, and answers 404 to anybody else (REQ-ABL-005). Verify: `AccessLinkControllerTest`.
- [x] 1.2 A link somebody else minted is listed without its anchor; the caller's own carries the anchor, `url` and `pageUrl` (REQ-ABL-005). Verify: `AccessLinkServiceTest`.

## 2. Revoking per subject

- [x] 2.1 `DELETE /api/access-links/{id}` with the link's subject revokes a link somebody else minted when the mint guard admits the caller; a link on another subject, or a caller the guard refuses, answers 404 (REQ-ABL-005). Verify: `AccessLinkControllerTest`, `AccessLinkServiceTest`.

## 3. Verification

- [ ] 3.1 Newman: a manager lists and revokes a member's link on a shared view on a live instance (planninq's `Public Share` folder covers the consumer side).
