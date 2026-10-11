# Tasks

- [x] T01 Index `referenceSchema` / `referenceKey`; leave out a key reference with an action other than RESTRICT, with a warning
- [x] T02 Walk: search the citing table for the key's value; skip a row without a key value
- [x] T03 Fail closed: unverifiable blocker; a missing citing table counts as no citation
- [x] T04 Unit tests on a neutral `label` / `note` fixture
- [x] T05 Property validator: `referenceSchema` and `referenceKey` in the vocabulary, onDelete accepted with `referenceSchema`, `referenceKey` refused with an action other than RESTRICT
- [x] T06 Spec requirement 16
