# Spec: consolidate-permission-handling

## Requirement: one evaluator decides anonymous access on both planes

OpenRegister MUST decide "may this caller perform this action" through a
single evaluator, used by both the object-level path
(`Service/Object/PermissionHandler`) and the entity-level path
(`Db/MultiTenancyTrait`).

Today they are independent. The object plane evaluates the `public` group for
a caller with no Nextcloud user; the entity plane returns `false` for that
caller unconditionally, with only `PHP_SAPI === 'cli'` and
`SystemOperationContext::isActive()` as escapes. The same question therefore
receives different answers depending on which code path a request happens to
enter.

### Scenario: an anonymous read of a schema granting `public`

- **GIVEN** a schema whose authorization grants the `public` group `read`
- **WHEN** a caller with no Nextcloud session reads its objects
- **THEN** the rows are served

### Scenario: an anonymous read of a schema NOT granting `public`

- **GIVEN** a schema whose authorization does not grant `public` `read`
- **WHEN** a caller with no Nextcloud session reads its objects
- **THEN** no rows are served

Both scenarios are required together. A change that served nothing to anybody
would satisfy the second alone, and that failure is indistinguishable from a
working access control until someone visits the site.

### Scenario: an anonymous write is fail-closed unless explicitly granted

- **GIVEN** a schema whose authorization grants `public` `read` but not
  `create`, `update` or `delete`
- **WHEN** a caller with no Nextcloud session attempts a write
- **THEN** it is refused

## Requirement: an entity may declare anonymous access, and it must be honoured

The entity plane MUST evaluate the same groups as the object plane. An entity
whose authorization grants `public` a verb permits an anonymous caller that
verb; one that does not, refuses.

This changes where a rule is HONOURED, not what a missing rule MEANS. Registers,
schemas, agents, webhooks, applications, sources, views, actions, mappings and
endpoints declare no authorization today, so every one of them MUST continue to
refuse an anonymous caller after this change.

### Scenario: an entity with no authorization block

- **GIVEN** a register that declares no authorization
- **WHEN** a caller with no Nextcloud session attempts to update it
- **THEN** it is refused, exactly as before this change

### Scenario: the blanket bypasses stay narrow

- **GIVEN** the consolidated evaluator
- **WHEN** its bypasses are enumerated
- **THEN** they are exactly `PHP_SAPI === 'cli'` and
  `SystemOperationContext::isActive()`

A third blanket bypass MUST break a test. The failure this guards against is a
public-facing write path being handed system trust to make a symptom go away —
which replaces a refusal with a silent bypass and is worse than the bug.

## Requirement: a refusal and a grant must both be observable

A refusal MUST record which plane decided it and whether the cause was an
absent rule or a rule that said no. Conflating those makes the audit useless
exactly when it is needed — an operator debugging a 403 cannot tell
"nobody configured this" from "this is configured to refuse you".

### Scenario: distinguishing absence from refusal

- **GIVEN** an anonymous caller refused at the entity plane
- **WHEN** the refusal is recorded
- **THEN** it names the entity type, the action, and whether any authorization
  block existed at all

### Scenario: the observability must not become a flood

- **GIVEN** a list endpoint refusing many rows for one caller
- **WHEN** the refusals are recorded
- **THEN** they are emitted once per (entity type, action) per request, not
  once per row

An unthrottled line on a list endpoint is a log flood, and a flood is what gets
logging switched off.
