# Spec: schema-slug-resolution

## ADDED Requirements

### Requirement: A slug resolved with a register in context MUST return that register's schema

Schema slugs are unique within a register, never across the instance. Any resolution path that holds a register MUST restrict the slug lookup to that register's schema set before falling back to a global lookup. Returning another app's schema is a defect, not an acceptable ambiguity.

#### Scenario: Two registers own the same slug
- **WHEN** registers `hermiq` and `openbuild` each own a schema with slug `agent`, and a caller resolves `agent` with `hermiq` in context
- **THEN** hermiq's schema is returned, identified by its id — not openbuild's, and not "whichever row the database returned first"

#### Scenario: The slug is not in the register
- **WHEN** a caller resolves a slug with a register in context and that register owns no schema with that slug
- **THEN** the scoped lookup returns null and resolution falls through to the existing global lookup, so no previously-working caller starts failing

#### Scenario: Identifier is a numeric id or a uuid
- **WHEN** the identifier is a numeric id or a uuid rather than a slug
- **THEN** resolution behaviour is unchanged, because a scoped slug lookup cannot match either and falls through cleanly

### Requirement: Resolution MUST NOT be poisoned by a per-request cache

`SchemaMapper::find()` caches resolutions for the lifetime of a request. A cache key that omits the register would let a global resolution earlier in a request satisfy a register-scoped resolution later in the same request, silently reinstating the defect.

#### Scenario: Global resolution happens first in the same request
- **WHEN** a single request resolves slug `agent` globally, and then resolves `agent` again with register `hermiq` in context
- **THEN** the second resolution returns hermiq's schema, not the cached global result

#### Scenario: Scoped resolution happens first in the same request
- **WHEN** a single request resolves slug `agent` with register `hermiq` in context, and then resolves `agent` globally
- **THEN** the global resolution is unaffected by the scoped one

### Requirement: Every slug-resolving path MUST be classified, and a deliberately global path MUST say why

The failure mode is silent — a wrong schema resolves successfully and returns plausible data — so a path that was missed is indistinguishable from one that was deliberately left alone. Every call site that resolves a schema by slug carries a recorded disposition.

#### Scenario: A path is left globally-resolving on purpose
- **WHEN** a slug-resolving path genuinely has no register available and is left global
- **THEN** an inline comment at that call site states why, and the path appears in the audit table in design.md with disposition `(c)`

#### Scenario: Reviewing the change
- **WHEN** a reviewer opens design.md
- **THEN** the audit table lists every slug-resolving call site with disposition `(a)` already scoped, `(b)` fixed, or `(c)` deliberately global, with no call site unclassified

### Requirement: `GET /api/schemas/{id}` MUST accept an optional register scope

The public schema route takes no register and is the path that misresolves today. It gains an optional scope rather than a breaking one: this is a foundation repository consumed by 18 apps, and converting a silently-wrong response into an error for every existing consumer at once is disproportionate to this change.

#### Scenario: Register supplied
- **WHEN** a caller requests `GET /api/schemas/agent?register=hermiq`
- **THEN** hermiq's schema is returned

#### Scenario: Register omitted
- **WHEN** a caller requests `GET /api/schemas/agent` with no register parameter
- **THEN** the response is byte-identical to the pre-change behaviour, so no existing consumer breaks

#### Scenario: Ambiguous slug, no register supplied
- **WHEN** a slug matches schemas in more than one register and no register parameter was supplied
- **THEN** the resolution is logged at debug level naming every candidate schema id, so the ambiguity leaves evidence instead of being silent
