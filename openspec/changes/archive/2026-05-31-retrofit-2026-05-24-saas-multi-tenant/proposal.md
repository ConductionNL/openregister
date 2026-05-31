# retrofit-2026-05-24-saas-multi-tenant

## Why

`TenantKeyService` manages per-tenant HMAC signing keys that back the
audit-trail hash chain (and any future per-tenant evidence-signing use
case). It was originally annotated against `openspec/changes/scholiq-deps/
tenant-key-api/tasks.md` — that change has since been archived, so the
behaviour is undocumented in the spec library while the service is wired
into the DI container in `Application.php` and exercised by
`tests/Unit/Service/TenantKeyServiceTest.php`.

Two private helpers (`fetchActiveRow`, `insertKey`) were observed in a
Bucket 2 cluster ("saas-multi-tenant") during the OR coverage scan and
were not coverable by any existing capability spec — they were triaged
out of `actions#REQ-002` (workflow action execution) because their
domain is HMAC tenant key persistence, not workflow execution.

This change establishes the `saas-multi-tenant` capability spec by
reverse-engineering the observed behaviour of those two helpers (and
the public methods they support) into 3 new REQs. Behaviour described
here is what the code does today, not aspirational.

## What Changes

- Create capability spec `openspec/specs/saas-multi-tenant/spec.md`
  with 3 REQs covering:
  - Per-tenant active-key lookup contract (single row, status=active,
    most-recent-first)
  - At-rest encryption + active-row insertion contract (ICrypto
    wrapping, status=active, ISO-8601 timestamp)
  - DI registration (TenantKeyService wired in `Application.php` so
    audit-trail signing has a singleton key provider)
- Annotate the two private helpers in
  `lib/Service/TenantKeyService.php` with `@spec` tags pointing at
  this change's `tasks.md`.

## Impact

- Affected specs: NEW capability `saas-multi-tenant`
- Affected code: docblock-only changes in
  `lib/Service/TenantKeyService.php` (no runtime behaviour change)
- Retrofit: documents existing behaviour; closes the
  `scholiq-deps/tenant-key-api` annotation gap left by that archive.
