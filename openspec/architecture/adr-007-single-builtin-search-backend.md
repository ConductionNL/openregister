# ADR-007: A single built-in database (Magic-Tables) search backend

**Status**: accepted (documents the decision as implemented; supersedes the
earlier pluggable-backend assumption)

**Date**: 2026-07-07

## Context

OpenRegister historically shipped pluggable search backends (external Solr and
Elasticsearch) alongside the built-in database search. The abstraction lingers
in names — `lib/Service/Settings/SearchBackendHandler.php`, `lib/Service/Search/`,
vectorisation handlers — which reads like a live strategy pattern with multiple
selectable backends. It is not, any more.

At HEAD, the external Solr and Elasticsearch backends have been **removed**. The
built-in database search over the Magic-Tables (`lib/Db/MagicMapper`) is the
sole backend. `SearchBackendHandler` documents this in its own header ("the
external Solr and Elasticsearch backends were removed, the built-in database
(Magic-Tables) search is the sole backend",
`lib/Service/Settings/SearchBackendHandler.php:34-36`) and
`updateSearchBackendConfig()` rejects any value other than `database`
(`:128-138`). The "solr hotfix" comments that disabled read-path RBAC in the
mappers (`RegisterMapper.php:246,500`, `SchemaMapper.php:261,540`) are the
residue of that removed integration.

Leaving this undocumented has two costs: authors reason about a backend
selection that no longer exists, and the disabled-RBAC hotfix that was
introduced *for* Solr is still in place with a "uncomment when ready" comment
and no owning decision.

## Decision

**The built-in database (Magic-Tables) search is OpenRegister's only search
backend. `SearchBackendHandler` is retained only as an API-compatibility shim
that reports `database` and rejects anything else. Vector search is an
augmentation of the database backend, not a separate selectable backend.**

### Numbered rules

#### Rule 1 — `database` is the only valid backend value

Any config or API attempting to select a non-`database` backend MUST be
rejected. `SearchBackendHandler` exists to enforce that, not to switch engines.

#### Rule 2 — No dormant external-backend code paths

Code paths, comments, or config that imply a live Solr/Elasticsearch backend
are misleading and should be removed or clearly marked as historical. In
particular, the "remove this hotfix for solr — uncomment when ready" RBAC
bypasses in the register/schema mappers are no longer justified by an active
Solr integration and MUST be resolved (see
`openspec/changes/restore-register-schema-rbac-enforcement`).

#### Rule 3 — Adding a backend is an ADR-level decision

Reintroducing an external search backend is a new architectural decision
requiring its own ADR (selection criteria, RBAC interaction, index-sync
contract), not a config toggle.

## Consequences

- (+) One code path to reason about, test, and secure.
- (+) Removes the standing justification for the disabled read-path RBAC.
- (−) Scaling full-text/vector search is now bounded by what the database
  backend can do; heavy-search consumers have no alternate engine to select.
- Follow-up: the disabled-RBAC hotfix is addressed in
  `openspec/changes/restore-register-schema-rbac-enforcement`.
