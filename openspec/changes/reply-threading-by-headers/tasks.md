# Tasks: reply-threading-by-headers

## 1. Data

- [ ] 1.1 Migration: `rfc_message_id` and `direction` on `openregister_email_links`, indexed on `rfc_message_id`.
- [ ] 1.2 Read the header at link time in `EmailProvider`; `BackfillEmailLinkMessageIdJob` one-shot with a kill switch.
- [ ] 1.3 Outbound link written by the dispatch leaf's e-mail channel when `object` is bound.

## 2. Resolve

- [ ] 2.1 `EmailsController::resolve()` with the header order, RBAC scoping and the granted system scope (ADR-099).
- [ ] 2.2 `by-message` also matches by `rfcMessageId`.

## 3. Tests

- [ ] 3.1 Unit tests for the order, the scopes, the backfill and the outbound link; Newman for resolve.
