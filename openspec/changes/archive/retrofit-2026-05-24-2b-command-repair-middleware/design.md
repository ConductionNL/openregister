# Design — retrofit-2026-05-24-2b-command-repair-middleware

> Retrofit change. Tasks describe retroactive annotation, not new implementation work. No code behavior changes.

## Scope decision: bundle three small namespace-word clusters

The Bucket 2b batch surfaces three namespace-word directories (`lib/Command/`, `lib/Repair/`, `lib/Middleware/`) with a combined 18 methods across 4 files. Each domain is small enough that a per-directory split would produce capabilities that are nothing but a `commands` / `repair-steps` / `middlewares` namespace bag — which the retrofit playbook explicitly tells us to avoid.

Instead, every method in this bundle extends an **existing behavioural capability** whose state the method touches:

| Method cluster | Extended capability | Why |
|----------------|---------------------|-----|
| `BackfillSystemOwnerCommand` | `auth-system` | Backfills the `__system__` owner sentinel; the sentinel itself is owned by the auth-system / RBAC model (`OrganisationService::SYSTEM_USER_ID_DEFAULT`). |
| `RematerialiseCalculationsCommand` | `computed-fields` | Re-evaluates schema-attached materialised expressions and persists them — the same save-time materialisation pattern documented in `computed-fields`. |
| `LogDanglingLinkedTypes` | `linked-entity-types` | The repair step scans `configuration.linkedTypes` against the live integration registry — directly extending the linkedTypes capability with an operational visibility hook. |
| `TenantQuotaMiddleware::afterException` | `tenant-quotas` | Converts the existing quota / status exception families into JSON envelopes — the on-wire shape of the existing enforcement layer. |

This keeps the spec graph tied to behaviour rather than file-system layout, matching ADR-008's annotation convention.

## REQ count budget

4 REQs total across 4 capabilities — under the 5-REQ-per-run cap with one slot reserved as a buffer. The repair-step domain is intentionally specced as one REQ (rather than splitting `extractLinkedTypes` / `safeStringAccessor` into separate REQs) because those accessors only exist to support `scan()`, and have no externally observable behaviour of their own.

## Annotation strategy

- Each method in `BackfillSystemOwnerCommand.php` gets an `@spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-1`.
- Each method in `RematerialiseCalculationsCommand.php` gets task-2.
- Each method in `LogDanglingLinkedTypes.php` gets task-3. The file's existing class-level `@spec openspec/changes/pluggable-integration-registry/tasks.md#task-11` (which references a non-existent change directory on this branch) is **left in place** — its presence is harmless and the future integration-registry change can re-link it. The new tag is added alongside, not as a replacement.
- `TenantQuotaMiddleware::afterException` gets task-4. The class-level `@spec` lines (which carry the older `retrofit-2026-04-23-...` / `retrofit-2026-04-30-...` pointers) are preserved.

Annotation operates per-file via the `@spec` tag inside the method docblock (or appended to the class docblock when the method has none). No code behaviour changes — only docblock edits.

## Risks

- **Pluggable-integration-registry dangling pointer.** `LogDanglingLinkedTypes` references a change that does not exist on this branch. Documented in the proposal and the linked-entity-types delta Notes; not auto-resolved.
- **`CalculationEvaluator` is imported but absent on this branch.** `RematerialiseCalculationsCommand` cannot construct at runtime here. Spec describes the observed source-level behaviour; a future scan should confirm the evaluator class lands.
- **No code is modified.** Annotation-only edits inside PHPDoc blocks. PHPCS / Psalm / PHPStan should treat these as no-ops; the linter hook may reflow whitespace, which is acceptable.
