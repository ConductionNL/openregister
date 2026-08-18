---
kind: code
---

# Proposal: consolidate-permission-handling

## Summary

OpenRegister decides "may this caller do this" in **two independent places**
that answer differently for the same request. Consolidate them behind one
handler that honours the `public` and `authenticated` groups uniformly, so an
anonymous call is permitted exactly when a schema or object says so — and
refused everywhere else for the same reason.

## Motivation

The two planes are not a layering; they are a split that nobody chose.

**Plane 1 — objects.** `Service/Object/PermissionHandler::hasPermission()` has
a real anonymous branch. For a request with no user it evaluates the `public`
group, and writes are separately fail-closed unless `public` explicitly grants
the verb (#1955):

```php
// For unauthenticated requests, check if 'public' group has permission.
return $this->hasGroupPermission(authorization: $authorization, groupId: 'public', …);
```

**Plane 2 — entities.** `Db/MultiTenancyTrait::hasRbacPermission()`, used by
`RegisterMapper`, `SchemaMapper`, `AgentMapper`, `WebhookMapper`,
`ApplicationMapper`, `SourceMapper`, `ViewMapper`, `ActionMapper`,
`MappingMapper` and `EndpointMapper`, has no such branch:

```php
$userId = $this->getCurrentUserId();
if ($userId === null) {
    if (PHP_SAPI === 'cli') { return true; }
    if (SystemOperationContext::isActive() === true) { return true; }
    return false;                       // <- everything else, unconditionally
}
```

An anonymous caller is denied **regardless of what any schema or object
declares**. The `public` group cannot reach this plane at all, and the only
escapes are full system trust.

### Both confusing results this week came from that split

* **opencatalogi serves anonymously and is correct to.** Measured live:
  `publication/page` returns 7 rows and `publication/publication` 3 rows to a
  caller with no session, because those schemas declare
  `read: [{group: public, match: {status: published}}, "authenticated"]` and
  plane 1 honours it. Nothing about that touches plane 2.

* **portaliq's first file attach failed and looked like a missing machine
  identity.** It was not. `FolderManagementHandler` lazily creates the
  register's folder and persists the id with `registerMapper->update()`, which
  enters plane 2 and is refused. Proven by measurement: the `portaliq` register
  had `folder=''`; one admin-authenticated attach set `folder='179'`; the
  anonymous path then worked and portaliq's e2e went to **57 passed / 1
  skipped / 0 failed**. The symptom appeared on the FIRST write only, which is
  why it read as a permission model problem rather than an initialisation one.

Two planes meant the same question — "is this anonymous caller allowed?" — got
answered by whichever code path the call happened to enter. That is not a
policy; it is an accident of routing.

## What this is NOT

**Not a loosening.** `public` must stay something an author assigns
deliberately. This change makes the `public` grant *reachable* on the entity
plane; it does not grant anything by default, and absent authorization must
keep meaning "no anonymous access" on both planes.

**Not the `rbac-default-authenticated` flip.** That change decides what
*absence* means. This one decides where *presence* is honoured. They touch the
same code and must be sequenced, but they are separate decisions and should
not be argued as one.

## Proposal

1. **One handler.** Entity-level checks call the same evaluator as object-level
   checks. `MultiTenancyTrait::hasRbacPermission()` becomes a thin delegation
   rather than a second implementation.

2. **The anonymous branch is uniform.** A caller with no Nextcloud user is
   evaluated against the `public` group on both planes. If a schema or object
   grants `public` the verb, it is allowed; otherwise refused. Writes stay
   fail-closed unless `public` explicitly names the write action.

3. **Entity-level `public` is opt-in and rare.** Register/schema/agent entities
   have no `authorization` block today, so under the consolidated handler they
   are refused for anonymous callers exactly as they are now. Nothing changes
   for them unless someone writes a rule — which is the point.

4. **System trust stays narrow.** `PHP_SAPI === 'cli'` and
   `SystemOperationContext::isActive()` remain the only blanket bypasses, and
   this change must not add a third. In particular the portal file-attach path
   should be fixed by initialising the register folder in a trusted context
   (openregister#2515), NOT by widening trust on a public-facing write path.

## Risks

**The dangerous failure is silent and it is a LOOSENING.** A consolidation that
accidentally lets the `public` group reach an entity verb it could not reach
before turns an unauthenticated caller into an editor of registers or schemas.
Every step needs the anonymous/authenticated PAIR measured, and the anonymous
half asserted as a refusal with the rule absent.

**The second failure is an outage that looks like a fix**: making both planes
refuse where one used to allow would take opencatalogi's public surfaces off
the air. `publication/page` returning 7 rows anonymously is the regression
test, not a curiosity.

## Sequencing

Before `rbac-default-authenticated` Task 3. Flipping what absence means, while
absence is still interpreted by two different code paths, doubles the surface
on which that flip has to be reasoned about.
