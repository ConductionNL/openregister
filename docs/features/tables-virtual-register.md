# Nextcloud Tables as Virtual Registers

Use any Nextcloud Tables table as a read-only **virtual register**: each table (or Tables View) becomes an auto-seeded Schema under a `tables` Register, and rows are served live through the object-source-provider seam — no copy, no sync job, no duplicate storage.

## Standards & architecture references

- Common Ground principle: data stays at the source; OpenRegister projects it.
- Hydra ADR-049 (virtual schemas via object-source providers).
- Spec: [`openspec/specs/tables-virtual-register/spec.md`](../../openspec/specs/tables-virtual-register/spec.md)

## Overview

When the Nextcloud Tables app is installed, OpenRegister reconciles a `tables` virtual register: one read-only Schema per Tables table, with a deterministic slug, seeded on app upgrade (repair step), on demand (`occ openregister:tables:sync`), and retired live when a table is deleted (Tables event listener). Tables column definitions are translated to JSON-Schema properties, and the standard objects API then lists, reads, counts, and paginates the table's rows live — as the acting user, so Tables' own ownership/share/context permissions decide visibility (denied reads are indistinguishable from absent objects).

## Key capabilities

- **Soft dependency**: no composer or install-time requirement on Tables; the provider registers via guarded class lookups and fails closed (empty results, logged warning) when the app is missing or disabled.
- **Column-type mapping**: text (line/long/rich/link) → string; number → number/integer with min/max (progress 0–100, stars 0–5); datetime → date-time/date/time formats; selection → enum, check → boolean, multi → enum array; usergroup → id/type object array.
- **Relation columns deep-link**: a Tables relation cell resolves to the referenced row's deterministic UUIDv5 in the referenced table's auto-seeded schema, so OR-level cross-schema linking works; falls back to the raw row id when the target schema is missing.
- **RBAC parity**: every read is performed as the acting user through Tables' own permission checks (ownership, shares, contexts); there is no enumeration oracle.
- **Pagination and View binding**: native limit/offset pushdown; other filters/sorts are applied provider-side; a schema can optionally bind a Tables **View** to inherit its server-side filter/sort.
- **Read-only by design**: writes to a Tables-bound schema are rejected by the object-source dispatch with an explicit read-only error.
