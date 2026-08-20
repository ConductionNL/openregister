# api-authorization Specification

## Purpose
TBD - created by archiving change gate-read-endpoint-authorization. Update Purpose after archive.
## Requirements
### Requirement: Webhook configuration and delivery logs are admin-scoped

Endpoints that read webhook configuration or delivery logs SHALL enforce the
same admin (or organisation-scoped) authorization as the webhook write
endpoints. Delivery-log rows contain unmasked request/response payloads and
target URLs and SHALL NOT be readable by a non-admin, non-owning user.

#### Scenario: Non-admin cannot list webhooks or read logs

- **WHEN** an authenticated non-admin user calls the webhook list, show, logs,
  log-stats, or all-logs endpoint
- **THEN** the request is rejected with HTTP 403 (or returns only rows scoped to
  the caller's organisation, if org-scoped read is enabled)
- **AND** no other organisation's webhook payloads or URLs are disclosed

### Requirement: Search-trail analytics are admin-scoped

Search-trail analytics endpoints SHALL require an admin caller, matching the
search-trail index/show endpoints. This covers statistics, popular terms,
activity, register/schema stats, and user-agent stats.

#### Scenario: Non-admin cannot read instance-wide search analytics

- **WHEN** an authenticated non-admin user calls a search-trail analytics endpoint
- **THEN** the request is rejected with HTTP 403
- **AND** instance-wide query text, user agents, and register/schema breakdowns
  are not disclosed

### Requirement: Search-trail destructive endpoints are admin-only

The destructive search-trail endpoints SHALL require an admin caller
unconditionally — `cleanup`, `destroy`, `destroyMultiple`, and `clearAll`. A
non-admin caller MUST NOT be able to delete any search-trail history,
individually or in bulk.

#### Scenario: Non-admin cannot delete search-trail history

- **WHEN** an authenticated non-admin user calls `cleanup`, `destroy`,
  `destroyMultiple`, or `clearAll` on the search trail
- **THEN** the request is rejected with HTTP 403
- **AND** no search-trail records are deleted

### Requirement: File download by id enforces object read RBAC

Downloading a file by id SHALL apply the same object-level read authorization as
the file `show` endpoint. The parent object SHALL be resolved and its read
permission evaluated before the file content is returned.

#### Scenario: Download without object read permission is denied

- **WHEN** an authenticated user requests a file by id whose parent object they
  are not permitted to read
- **THEN** the request is rejected with HTTP 403
- **AND** the file content is not returned

### Requirement: GraphiQL explorer does not relax CSP for non-admins

The GraphiQL explorer page SHALL NOT grant `unsafe-inline`, `unsafe-eval`, or a
third-party CDN script domain to non-admin users.

#### Scenario: Explorer is admin-gated or self-hosted

- **WHEN** a non-admin user requests the GraphiQL explorer page
- **THEN** either the request is rejected (admin-only)
- **OR** the page is served with self-hosted assets and no `unsafe-eval`/
  `unsafe-inline`/CDN CSP allowance

