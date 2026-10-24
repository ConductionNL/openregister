# Proposal: property-explicit-reveal

## Why

Board DqContact says "Het tonen van het BSN wordt gelogd." Before this change the person record carried the full BSN
to every reader allowed to see it, the page masked it in the browser, and nothing recorded the deliberate look.

## What changes

A property's authorization block may say `"reveal": "explicit"`. Such a value leaves OpenRegister masked
("*****7609") on every read route, administrators and `_rbac: false` reads included:

- the object render boundary (RenderObject: single and list renders, entity and array rows, after decryption), with
  the `@self` name / summary / description and relations copies withheld;
- GraphQL, access links and MCP resource reads (always masked);
- CSV/XLSX exports (the column is written masked);
- object history: `changed.old/new` masked, the `@self` copies withheld.

It is shown only to `GET /api/objects/{register}/{schema}/{id}?_reveal=<property>`:

- `_reveal` is read by ObjectsController::show only, and grants that one object only; an extended or listed object
  stays masked;
- the property's read rule runs first;
- the reveal is recorded through the RevealCollector when the property is audited (`audit: true`).

The value must not be learnable another way:

- filtering and sorting on it (`?prop=`, `_order`) are refused with a 400 (ExplicitRevealQueryException);
- it takes no part in free-text `_search` (PropertySearchProfile);
- no facet is offered on it.

Two kinds of read see the value:

- a system operation (SystemOperationContext);
- server code that asks for it on purpose with `ExplicitReveal::trustedDuring()`.

The same two may filter on it.

A write that sends the mask back keeps the stored value: single saves (ObjectService, before validation) and bulk
saves (SaveObjects).

## Scope

- `Service/Rbac/ExplicitReveal` (grants, trust, pure rules), `Service/Rbac/ExplicitRevealMasker` (mask, reveal,
  record), `Exception/ExplicitRevealQueryException`.
- The routes above. PropertyRbacHandler no longer records an explicit-reveal property (the masker does).
- Opt-in per property: inert for every schema that does not declare it.

Not covered here: the administrator audit-trail listing (`/api/audit-trails` for an admin) returns entries
unredacted, as it does for every other property.
