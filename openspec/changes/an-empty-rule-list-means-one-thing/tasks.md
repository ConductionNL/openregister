# Tasks: an-empty-rule-list-means-one-thing

## 1. The decision

- [x] 1.1 State, in `PropertyRbacHandler`, that an unnamed action has no opinion
      at that layer rather than being accessible to anyone.
- [x] 1.2 Pin the distinction with a test, including the control that a named
      action still refuses somebody outside it.

## 2. Not done, deliberately

- [ ] 2.1 Harmonise the two layers. Measured: 8 property blocks in the fleet, all
      8 partial, so a fail-closed property layer breaks every one of them.
- [ ] 2.2 Refuse a block that restricts `read` without naming `update`. It is a
      blind write rather than a disclosure, and which of the two a schema wants is
      that schema's judgement. Named in the class so an author meets it.
