---
kind: code
depends_on: []
---

# Proposal: api-atomic-batch

## Summary

A client sends several creates, updates and deletes in one request with `atomic: true`, and either all of them are written or none is. Today the bulk endpoint writes row by row and keeps what succeeded.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### api-batch, send several changes in one request that all succeed or all fail together

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `api`, source `competitor-derived`.

Matrix evidence, verbatim:

> lib/Controller/BulkController.php:559 save, route appinfo/routes.php:1282. Not all-or-nothing: docblock :476-477 'Rows that DID write are not rolled back , this endpoint has never been transactional'; only per-chunk transactions lib/Db/MagicMapper/MagicBulkHandler.php:491

Competitor cells rated `yes`, verbatim:

- directus: source read at v12.4.1, not driven: directus:api/src/services/items.ts:446 createMany and :684 updateBatch run in one database transaction, so a batch POST or PATCH with an array rolls back as a whole; nested relational writes share the same transaction (items.ts:154)
- pocketbase: source read at v0.40.4, not driven: pocketbase:apis/batch.go:28 POST /api/batch, :193 all requests run in one RunInTransaction; enabled in settings pocketbase:core/settings_model.go:133

## Why

An integration that writes an order and its lines, or a case and its documents, cannot leave half of it behind when one row fails. Two competitors offer an all-or-nothing batch; the bulk endpoint here says in its own docblock that it has never been transactional.

## What is built today

- `lib/Controller/BulkController.php` save writes many rows; rows that wrote are not rolled back (docblock).
- Per-chunk transactions only, in `lib/Db/MagicMapper/MagicBulkHandler.php`.

## What changes

1. The bulk save accepts `atomic: true`. The request runs in one database transaction; the first refused row rolls back every row and the answer names the row index and the reason.
2. Side effects that leave the transaction (events, webhooks, audit entries) are dispatched only after commit.
3. A size limit for atomic batches, returned in the refusal when exceeded.

## Out of scope

- Atomic batches across registers on different storage backends.
