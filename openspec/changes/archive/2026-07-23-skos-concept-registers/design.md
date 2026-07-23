# Design: skos-concept-registers

## Approach
Vocabularies are ordinary register data: one bundled vocabulary register with
`conceptScheme` + `concept` schemas, an idempotent importer, bundled TOOI
fixtures, and a thin resolution API over the existing object search path. No
new storage machinery — the whole point is that OR already owns objects,
relations, RBAC, search and audit.

## Decisions

### D1 — Schema-first, ADR-011 naming
SKOS core terms map to schema.org-aligned camelCase property names
(`prefLabel`, `broader`, `inScheme` kept as SKOS terms where schema.org has no
equivalent — they ARE the standard vocabulary of this domain). Multilingual
labels are objects keyed by BCP-47 tag (`{"nl": "...", "en": "..."}`) —
consistent with the register-i18n direction, no parallel i18n mechanism.

### D2 — Relations in the canonical dialect only
`broader`/`narrower`/`related`/`inScheme` are `format: uuid` + `$ref`
properties per ADR-062 rules 6/7 (relation-dialect gate). The source SKOS URI
remains the durable identity; the importer resolves URI→object id at import
time and maintains both directions of `broader`/`narrower`.

### D3 — Deprecate, never delete (dangling-reference safety)
Leaf apps hold concept references long-term (a Woo publication's
informatiecategorie must resolve for its whole retention life). Re-imports
therefore soft-deprecate missing concepts (a `deprecated: true` property set
by the importer) instead of deleting.

### D4 — Importer formats: RDF via a small parser, CSV as first-class
TOOI value lists ship as CSV/JSON fixtures (no network at install). For RDF
serializations use a lightweight parser (e.g. easyrdf already in the
dependency tree, else JSON-LD only in v1 with Turtle/RDF-XML follow-up) —
the acceptance bar is the TOOI + one generic SKOS JSON-LD fixture importing
green; exotic RDF is not v1 scope.

### D5 — Resolution API = thin controller over ObjectService
By-URI and by-(scheme, notation) lookups are metadata-filtered
`searchObjectsPaginated` calls; label search is the standard search path with
a language-agnostic match over the label maps. Public reads ride the same
rate-limit posture as other public OR endpoints (ADR-054).

## Leaf reintegration (out of scope here, tracked per leaf)
OpenCatalogi: `TooiVocabularyService`/`DcatVocabularyService` become readers
of the vocabulary register (ADR-022 consume change in the opencatalogi repo).
softwarecatalog (GEMMA), decidesk (ORI) follow the same pattern.
