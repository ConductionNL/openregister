# Tasks: a-property-says-what-the-portal-may-do-with-it

- [x] 1.1 Add `portal` (`none`, `read`, `edit`) to the known keys in `PropertyValidatorHandler`, refusing another value.
  - In the property vocabulary and in `CLOSED_MODIFIER_VALUES`, so `/api/schemas/property-vocabulary` lists the allowed values; the refusal reads `Invalid portal "write" at '/subject'. Must be one of: none, read, edit`. Test: PropertyValidatorHandlerTest
- [x] 1.2 On a read with a portal subject, leave out `none`/absent properties.
  - Decision (this pass): the objects API has no portal subject of its own, so a request carrying `X-Portal-Subject` is the read "with a portal subject". `PortalPropertyMiddleware` filters objects#show and #index, and the object #create/#update/#patch answer with, through `PortalPropertyPolicy::readable()`; the header's presence switches it on, verified or not, because the rules only take away. Tests: PortalPropertyPolicyTest, PortalPropertyMiddlewareTest
- [x] 1.3 On a write with a portal subject, refuse a change to a property that is not `edit`, naming it, writing nothing.
  - Checked before the controller runs, against the stored object read through RBAC (`refusedChanges()`): a value re-sent unchanged is no change, PUT counts a dropped stored value as a change, a create may send only `edit` properties. 403 `portal-property-not-editable` with `properties`. Tests: PortalPropertyPolicyTest, PortalPropertyMiddlewareTest
- [x] 2.1 PHPUnit for 1.1 to 1.3, and a Newman case per scenario.
  - `tests/integration/openregister-portal-property.postman_collection.json`, registered in `tests/newman/run-all.sh` as `portal-property`. Run 2026-11-25 on a sandbox Nextcloud 34.0.6 with MariaDB: 17 requests, 30 assertions, 0 failed
- [ ] 3.1 Tell dossiq (competitor-parity 15.7) and portaliq that the key exists.
