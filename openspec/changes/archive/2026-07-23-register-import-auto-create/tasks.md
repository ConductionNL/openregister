# Tasks: register-import-auto-create

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 5. -->

## 1. Import path

- [x] 1.1 In `ImportService::importFromJson`, detect bundle (register envelope + schemas) vs plain object list; when the target register is absent and the payload is a bundle, create the register + schemas via the existing register/schema import path, then import objects into it (REQ-IMP-AC-01)
  - Reuse the configuration/register-import machinery — no duplicated creation logic (ADR-011).

- [x] 1.2 Idempotency: look up the register by slug before create; if it exists, skip creation and proceed to the existing schema/object upsert (no duplicate register) (REQ-IMP-AC-03)

- [x] 1.3 Clear failure: when a missing register is referenced and the payload is not a creatable bundle, throw a domain error naming the missing slug and stating the two remedies; surface as an actionable 4xx, not a 500 (REQ-IMP-AC-02)

## 2. Verification

- [x] 2.1 Test: import a bundle for a non-existent register → register + schemas created, objects land in it; re-import same bundle → no duplicate register (REQ-IMP-AC-01, REQ-IMP-AC-03)
  - Run in the `nextcloud:34` container: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit`.

- [x] 2.2 Test: import referencing a missing register with no creatable metadata → clear error naming the slug, mapped to a 4xx by the controller (REQ-IMP-AC-02)

Acceptance criteria:
- A register bundle imports cleanly into an instance that lacks the register.
- An un-createable missing-register import fails with an actionable message, never a silent no-op or opaque 500.
- Re-importing an existing-register bundle does not duplicate the register.
