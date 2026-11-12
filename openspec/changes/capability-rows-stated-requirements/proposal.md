---
kind: code
---

# Proposal: capability-rows-stated-requirements

## Summary

Three rows in Open Register's capability matrix had no requirement that says them.
This change writes those three requirements, so each row can record the heading it
rests on.

## Rows this closes

| matrix | row | capability | rating after this change |
|---|---|---|---|
| openregister | mod-json-schema | Describe a record type in standard JSON Schema that other tools can read | built |
| openregister | api-urn | Address a record by a permanent URN that survives a move | specified |
| openregister | file-checksum | Keep a checksum of every stored file so anyone can prove later that it has not changed | specified |

## Why

- **mod-json-schema.** `openapi-generation` describes the OpenAPI document of a
  register. No requirement says that one schema can be downloaded on its own as a
  standard JSON Schema document. The code does it already (`Schema::getSchemaObject`,
  the Download action on the schema page), so this requirement records built behaviour.
- **api-urn.** `urn-resource-addressing` keeps a URN stable across a domain change and
  a register slug change, not across a move to another register or schema.
  `UrnService` builds the URN from the record's current register and schema slugs, so a
  move changes it today. The open change `identity-survives-a-move` keeps the old URL
  answering; this requirement adds the URN to that promise. The row moves to `specified`.
- **file-checksum.** Checksums are computed only at e-Depot transfer and for chunked
  uploads. Nothing stores a checksum when a file is uploaded, so the row moves to
  `specified`.

## What changes

- ADDED requirement in `runtime-schema-api`: a schema can be downloaded as a standard
  JSON Schema document.
- ADDED requirement in `urn-resource-addressing`: a record keeps its URN when it moves.
- ADDED requirement in `file-actions`: a stored file keeps a checksum from the moment it
  is uploaded.

## Impact

Specs only. The two `specified` rows need code: the URN resolver must follow a move,
and the file pipeline must compute and store the checksum.
