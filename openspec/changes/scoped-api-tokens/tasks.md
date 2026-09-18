# Tasks: scoped-api-tokens

> 🔴 **DO NOT ARCHIVE THIS YET, and not because it cannot be archived.**
>
> The structural defect in `specs/auth-system/spec.md` that used to refuse
> every delta against that spec is fixed (#3918), and `openspec validate
> scoped-api-tokens --strict` is now clean. That measurement is about
> ARCHIVABILITY. It is not a measurement of doneness, and the two were
> conflated once already — by me, in the #3918 PR body — so it is written down
> here where the next person will meet it.
>
> Measured 2026-09-18, of the five requirements in the delta:
>
> - **built:** "A token or Consumer may carry a grant narrower than its user"
>   (#3913).
> - **partial:** REQ-SAT-003, the required end date — required at issue and
>   enforced at use, with no warning before it lapses.
> - **partial:** REQ-SAT-005, the rate limit — carried and validated on the
>   grant, with no counter, no refusal and no outbound allowlist.
> - **no implementation at all:** "The effective grant is visible and writes
>   name the token" (`whoami`, `actorVia`), and REQ-SAT-004, the service
>   account owned by a team.
>
> Archiving folds all five into the canonical spec and moves this change out of
> `openspec/changes/`. Two of them would then be requirements the system does
> not meet, stated in the spec with nothing pointing at the gap — and the
> thirteen open tasks below, each of which carries the reason it is open, would
> stop being anywhere anyone looks. That is the same failure as a comment
> claiming coverage elsewhere: it stops people looking without making the thing
> true.
>
> Archive it when REQ-SAT-004 and the visibility requirement have an
> implementation, or split those two out into their own change and archive the
> rest.

## 1. Data

- [x] 1.1a `grant` on `Consumer`, inside the existing
      `authorizationConfiguration` JSON column, so there is **no migration** and
      no second place for a Consumer's settings. `TokenGrant` reads it;
      `TokenGrantValidator` refuses it.
- [x] 1.1b The validator: no verbs at all, `manage`, an unknown verb, a verb
      the issuer lacks, no end date, an end date in the past, a
      present-but-empty scope axis, a non-positive rate limit.
- [ ] 1.1c The personal token store. A Nextcloud app password is not an
      OpenRegister row, so a grant on one needs either a table of our own keyed
      by token id or an upstream hook; the Consumer half is the one the row's
      supplier scenario is about and it is done.
- [ ] 1.1d The `match` row condition is carried and returned but not yet
      evaluated: it belongs in the conditional-scope evaluator beside the other
      rules, which is task 2.1's SQL half.

## 2. Evaluation

- [x] 2.1a The grant layer in `PermissionHandler::resolveAuthorization()` —
      the one step every path takes, which is what makes the PHP verdict and
      the SQL verdict identical by construction rather than by two
      implementations agreeing.
- [x] 2.1b 🔴 The ceiling is ALSO consulted in `hasGroupPermission()` ahead of
      the admin and owner bypasses, which return true before reading the block
      at all. Without that, the grant would narrow a supplier and not the
      administrator who issued the token, and would let any token write its
      holder's own objects.
- [ ] 2.1c The SQL RBAC builder's own reading of the marker, and
      `@self.tokenSubject`. The narrowed block reaches `MagicRbacHandler`
      through `resolveSchemaAuthorization()`, which delegates here, so a list
      is already filtered by the narrowed verbs; the row condition is 1.1d.
- [ ] 2.2 `actorVia` and `whoami`. `TokenGrant::toArray()` and `tokenId` are
      the data both need; the audit writer and the whoami endpoint are the
      two callers, and neither is written yet.

## 3. Surfaces

- [ ] 3.1 Grant editor with object preview on the personal token page and the Consumer admin page.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/scoped-token.spec.ts`: issue a scoped token, list and write with it.
- [x] 4.2a 25 unit tests: every refusal, the intersection, the schema outside
      scope, the expired token, the forged marker, the unreadable marker, the
      marker as a control key, and the two bypasses. Three mutation checks.
- [ ] 4.2b List filtering end to end, `actorVia`, and Newman with a real
      scoped token: all need an instance.

## Discovery cluster 40

- [x] C40.1 Required at issue, refused without one, and enforced at use:
      `TokenGrant::isExpired()` reads a MISSING end date as expired, because
      "no end date" and "never expires" are the same string and opposite
      facts.
- [x] C40.2a `lapsesSoon()` answers who should be warned.
- [ ] C40.2b The notification that carries the warning, and the recorded
      renewal.
- [ ] C40.3 A service account principal owned by a team, holding grants and tokens, with no interactive sign-in (D-C40-2).
- [x] C40.4a The limit is carried on the grant and validated as a positive
      number of calls per minute.
- [ ] C40.4b The counter and the refusal that names it, which needs a shared
      cache and a middleware.
- [ ] C40.5 An administered outbound allowlist checked at save (D-C40-3).
- [ ] C40.6 Tests: the missing end date refusal, the expired token, the leaver who does not break the integration, the interactive sign-in refusal, the allowlist refusal at save.
- [ ] C40.7 Hand over to the dossiq and integriq lanes with candidate ids C-access-and-privacy-35, -42, -43, -44 and -70, noting that C-access-and-privacy-35 is already answered by `account-self-service`.
- [x] C40.8 Reported, unfixed, in the PR body: `openspec validate --strict`
      confirms it, at `specs/auth-system/spec.md` line 888. It is on a line
      this change does not touch, so it belongs to the debt sweep.
