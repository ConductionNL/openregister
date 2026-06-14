# Tasks — retrofit-2026-05-24-b-exception-all

Reverse-spec retrofit. Tasks below describe the observed behavior each
annotated method group already satisfies. No code changes other than `@spec`
annotations.

## task-1 — Reference-validation diagnostic payload shape

Extend `reference-existence-validation`. Pin the exception-level structured
diagnostic contract for the two `ValidationException` subclasses on the
reference-existence path:

- `ReferenceValidationException::toArray()` MUST return keys `propertyName`,
  `referencedUuid`, `targetSchemaSlug`, `targetRegister`, `message`, `code`.
- `CircularReferenceException::toArray()` MUST return keys `referencedUuid`,
  `targetSchemaSlug`, `cycle`, `message`, `code`.
- The structured getters (`getPropertyName`, `getReferencedUuid`,
  `getTargetSchemaSlug`, `getTargetRegister`, `getCycle`) MUST expose the
  constructor-supplied values unchanged.
- Both subclasses MUST default `code` to 422 and build a structured default
  message when none is supplied.

Methods annotated: `CircularReferenceException::__construct`,
`::getReferencedUuid`, `::getTargetSchemaSlug`, `::getCycle`, `::toArray`;
`ReferenceValidationException::__construct`, `::getPropertyName`,
`::getReferencedUuid`, `::getTargetSchemaSlug`, `::getTargetRegister`,
`::toArray`.

## task-2 — Provider-unavailable cause classification (AD-23)

Extend `generic-integrations`. Pin the actionable-error contract for
`ProviderUnavailableException`:

- The class MUST expose exactly four cause constants:
  `CAUSE_OPENCONNECTOR_DOWN = "openconnector-down"`,
  `CAUSE_OPENCONNECTOR_SOURCE_MISSING = "openconnector-source-missing"`,
  `CAUSE_UPSTREAM_SERVICE_DOWN = "upstream-service-down"`,
  `CAUSE_PROVIDER_AUTH = "provider-auth"`.
- `getCause()` MUST return the constructor-supplied cause unchanged.
- `getDetails()` MUST return `{cause: <cause>}` for the UI's `details.cause`
  rendering.

Methods annotated: `ProviderUnavailableException::__construct`, `::getCause`,
`::getDetails`.

## task-3 — Append-only refusal response envelope

Extend `audit-trail-immutable`. Pin the structured HTTP 405 body for
append-only schema-write refusals:

- `AppendOnlyException::toResponseBody()` MUST return keys `error`, `message`,
  `schema`, `operation`.
- The `error` value MUST be the canonical code `SCHEMA_APPEND_ONLY`.
- The constructor MUST set HTTP code 405 and default `operation` to `update`.
- `getSchemaIdentifier()` / `getOperation()` MUST expose the constructor args
  unchanged.

Methods annotated: `AppendOnlyException::__construct`, `::getSchemaIdentifier`,
`::getOperation`, `::toResponseBody`.

## DROP — exception plumbing (not annotated)

`LockedException::__construct`, `RegisterNotFoundException::__construct`,
`SchemaNotFoundException::__construct`, `HookStoppedException::__construct`,
`HookStoppedException::getErrors` — pure ctor/getter plumbing; throwing
behavior is covered by `object-lifecycle` / `schema-hooks` host scenarios. See
proposal.md for rationale. No `@spec` annotation added.
