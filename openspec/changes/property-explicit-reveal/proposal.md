# Proposal: property-explicit-reveal

## Why

Board DqContact says "Het tonen van het BSN wordt gelogd." Today it is not true:

- the person record carries the full BSN to every reader allowed to see it;
- the page masks it in the browser;
- "Tonen" only unmasks a value the page already had;
- and because the value was delivered, `sensitive-field-reveal-audit` (when the property is audited) records every
  page view as a reveal, not the deliberate look.

The coordinator decided on 24 Oct to make the line true with a server-side reveal.

## What changes

A property's authorization block may say `"reveal": "explicit"`. On the objects API (ObjectsController, marked
by ExplicitRevealMiddleware):

- a reader who may read the property gets its mask, "*****7609", and no reveal is recorded;
- a read that names the property in `_reveal` (`?_reveal=citizenServiceNumber`) gets the value:
  - the property's read rule runs first, so a reader without read rights gets nothing;
  - an audited property (`audit: true`) records the reveal through the RevealCollector, flushed by
    RevealAuditMiddleware as before;
- an update that sends the mask back keeps the stored value (ObjectService, before validation), so the edit
  round trip GET → form → PUT cannot overwrite the number with its mask.

Off the objects API (an app's server code through ObjectService) nothing is masked. An administrator read with RBAC
off (`_rbac: false`) is not filtered at all, as today.

## Scope

- `Service/Rbac/ExplicitReveal`, `Middleware/ExplicitRevealMiddleware`, PropertyRbacHandler masking,
  ObjectService restore, Application registration.
- Opt-in per property: inert for every schema that does not declare it.
