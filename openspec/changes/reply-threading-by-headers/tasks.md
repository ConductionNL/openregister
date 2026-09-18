# Tasks: reply-threading-by-headers

## 1. Data

- [ ] 1.1 Migration: `rfc_message_id` and `direction` on `openregister_email_links`, indexed on `rfc_message_id`.
- [ ] 1.2 Read the header at link time in `EmailProvider`; `BackfillEmailLinkMessageIdJob` one-shot with a kill switch.
- [ ] 1.3 Outbound link written by the dispatch leaf's e-mail channel when `object` is bound.

## 2. Resolve

- [ ] 2.1 `EmailsController::resolve()` with the RBAC scoping and the granted
      system scope (ADR-099). **The HEADER ORDER and the refusals are built**
      in `lib/Service/Notification/ReplyThreadResolver.php`; the controller,
      the scoping and the routes are not.
      `In-Reply-To` is read before `References`, and `References` is walked
      from its LAST entry because that is the nearest ancestor.
      **Nothing is ever guessed.** No fuzzy match, no prefix match, no subject
      fallback — the `[ZAAK-…]` tag is deliberately not read, because it is
      the guess that files one citizen's reply on another citizen's case.
      **A chain naming two objects resolves NEITHER** and names both as
      candidates: `References` accumulates every ancestor, and somebody
      replying about case A while quoting a mail about case B hands us both.
      Picking the first, the last or the newest is a coin flip with a
      disclosure on one side.
      **A reply with no usable reference is `unthreaded`, a named answer.** It
      is real, it arrived and a person has to see it; an empty result leaves
      it in a queue nobody reads while the system looks healthy. Only a
      `threaded` result may be filed without a human.
- [ ] 2.2 `by-message` also matches by `rfcMessageId`.

## 3. Tests

- [ ] 3.1 Unit tests for the scopes, the backfill and the outbound link;
      Newman for resolve. **The order and the refusals are tested**:
      `tests/Unit/Service/Notification/ReplyThreadResolverTest.php` (13),
      including two objects belonging to different people resolving neither,
      a partial id never matching, and case-insensitive header names.
