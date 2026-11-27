# Tasks: a-property-says-what-the-portal-may-do-with-it

- [ ] 1.1 Add `portal` (`none`, `read`, `edit`) to the known keys in `PropertyValidatorHandler`, refusing another value.
- [ ] 1.2 On a read with a portal subject, leave out `none`/absent properties.
- [ ] 1.3 On a write with a portal subject, refuse a change to a property that is not `edit`, naming it, writing nothing.
- [ ] 2.1 PHPUnit for 1.1 to 1.3, and a Newman case per scenario.
- [ ] 3.1 Tell dossiq (competitor-parity 15.7) and portaliq that the key exists.
