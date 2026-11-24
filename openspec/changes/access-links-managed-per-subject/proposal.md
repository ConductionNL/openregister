---
kind: code
---

# Proposal: access-links-managed-per-subject

## Why

`GET /api/access-links` lists only the links the caller minted, and
`DELETE /api/access-links/{id}` revokes only the caller's own. An app that
publishes a shared subject (planninq's project board, a view the whole
project team works from) cannot show its managers the links a member made,
and cannot let them switch one off. The only way to end such a link today is
to delete the subject.

## Decision

Whoever may publish a subject may also see and revoke every link on it. The
mint guard already answers "may this caller publish this subject"; the same
answer now gates listing and revoking on that subject. Reasons:

- It adds no new principal or role: an app grants publishing through its own
  rules on the subject, and gets managing for free.
- Revoking only ever narrows access, so letting more people end a link is the
  safe direction.
- A link somebody else minted is listed WITHOUT its anchor. The caller may see
  who made it, its end date, password flag and use count, and end it; they
  cannot copy and hand on a link they did not make. Their own links keep the
  anchor and both URLs as before.

Without `subjectType` and `subjectId` both endpoints behave exactly as before.

## What changes

- `GET /api/access-links?subjectType=&subjectId=`: every link on the subject,
  for a caller the mint guard admits; 404 otherwise, the same answer a mint
  for it gives.
- `DELETE /api/access-links/{id}?subjectType=&subjectId=`: the caller's own
  link as before, or any link on the named subject when the guard admits the
  caller. A link on another subject answers 404.
- `AccessLinkService::listForSubject()`, `::revokeOnSubject()`, and
  `AccessLinkMapperInterface::findBySubject()` (the mapper already had it).

## Out of scope

Switching another person's link off and on (`PUT`) stays owner-only: the
consuming app needs revoke, and a reversible switch on somebody else's link
is a separate question.
