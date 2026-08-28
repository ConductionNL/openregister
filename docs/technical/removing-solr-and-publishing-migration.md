# Migration: removing SOLR and Register/Schema publishing

This release removes two deprecated subsystems from OpenRegister:

1. **The SOLR search index and the entire external search backend abstraction**
   (including the Elasticsearch backend). Full-text search, faceting, and
   aggregation now run exclusively on the built-in database (Magic-Tables /
   PostgreSQL) path.
2. **Register/Schema publishing** — the `published`/`depublished` columns and the
   publish/depublish UI. Anonymous visibility of registers and schemas is now
   decided entirely by RBAC.

Both removals are **BREAKING**. This guide describes the operator actions
required so nothing silently breaks after upgrading.

## 1. SOLR / external search backend

No data migration is required: the SOLR index was a derived copy of data that
already lives in the database. After upgrading:

- Remove the `solr` (and `zookeeper`) services from your deployment — they are no
  longer used. The docker compose `solr` profile and `resources/solr/`
  configsets have been removed from the app.
- The `search_backend` configuration value is ignored; the active backend is
  always `database`.
- The following HTTP endpoints have been **removed** and now return `404`:
  `/api/settings/solr*`, `/api/solr/*` (collections, configsets, fields,
  dashboard, setup, manage), `/api/settings/solr-info`,
  `/api/settings/solr-facet-config`, and the SOLR-backed file endpoints
  (`/api/solr/warmup/files`, `/api/solr/files/*`, `/api/search/files/keyword`,
  `/api/files/chunks/process`, `/api/files/chunks/stats`).
- In-process semantic/hybrid vector search (PostgreSQL `pgvector`) is unchanged;
  its endpoints (`/api/search/files/semantic`, `/api/search/files/hybrid`,
  `/api/settings/search/semantic|hybrid`, `/api/objects/vectorize/*`,
  `/api/vectors/test-embedding`, …) keep working.

> Note: SOLR's relevance ranking (BM25/TF-IDF) is gone. Database search uses
> `ILIKE`/`pg_trgm` matching without relevance scoring.

## 2. Register / Schema publishing → RBAC

The `published`/`depublished` columns on the registers and schemas tables are
dropped by migration `Version1Date20260624000000`. Previously, a register or
schema was visible to **anonymous** callers when it had a `published` timestamp
(and was not depublished).

Anonymous visibility is now expressed as an **RBAC rule** on the entity's
`authorization` block: a register or schema is anonymously readable when its
`authorization.read` grants access to the `public` group.

### Required operator action

For **every register or schema that was previously published** and must stay
publicly readable, add `public` to its `read` authorization **before or right
after** upgrading. Minimal form:

```json
{
  "authorization": {
    "read": ["public"]
  }
}
```

To express a time-based publication window (the equivalent of a future
`published` date), use the RBAC `$now` dynamic variable against a date property
on the object — the same pattern the object-level deprecation established:

```json
{
  "authorization": {
    "read": [
      { "group": "public", "match": { "publicatiedatum": { "$lte": "$now" } } }
    ]
  }
}
```

There is **no automated data migration** of publication state into RBAC rules:
the intended audience of a previously-published resource cannot be inferred
safely, so operators add the rule explicitly.

> Rollback: the column-drop migration is destructive. It is sequenced as the
> last deploy step so application code can be reverted independently before the
> columns are dropped. After the columns are dropped, restoring them requires a
> database backup.

## 3. File auto-share: `autoPublish` → `autoShare`

The schema/property config key `autoPublish` conflated object publishing (now
removed) with Nextcloud **file sharing**. The file auto-share behaviour is
preserved under a new, file-scoped key: **`autoShare`**.

- Rename any schema or property file-config `"autoPublish": true` to
  `"autoShare": true` to keep auto-creating a public share for uploaded files.
- `autoPublish` is **no longer read for any purpose**. A schema still carrying
  `autoPublish` simply stops auto-sharing and logs a one-time deprecation
  warning naming `autoShare`.
- This is a manual, documented rename — there is no automated config rewrite.
