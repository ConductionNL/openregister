# Tasks: anonymising-as-an-archival-outcome

## 1. The fourth action

- [ ] 1.1 `anonymiseren` joins the archival action vocabulary and its validator.
- [ ] 1.2 The nomination deriver can produce it, and a destruction list can carry it.
- [ ] 1.3 A reviewer may answer an entry with it, recorded like the other three answers.

## 2. The profile

- [ ] 2.1 An anonymisation profile on the schema: per property, remove, fixed value, stable pseudonym or generalise.
- [ ] 2.2 Schema save refuses a profile naming a property the schema does not declare.
- [ ] 2.3 An outcome or result type names the archival action, so the choice is configuration.

## 3. The act

- [ ] 3.1 Execution runs through the existing pseudonymise primitive, per the declared profile.
- [ ] 3.2 The search index, the history projection and every derived copy are updated in the same act.
- [ ] 3.3 The anonymised values are removed from the audit trail's stored diffs, an entry records the act, and the chain is re-sealed.
- [ ] 3.4 A record under a legal hold is not anonymised, and the refusal names the hold.
- [ ] 3.5 A failure to reach a derived copy fails the whole act; nothing is half-anonymised.

## 4. The report

- [ ] 4.1 The act reports the properties changed and the properties deliberately kept.
- [ ] 4.2 The report is readable afterwards from the record and from the run.

## 5. Tests

- [ ] 5.1 Unit tests for each of the four treatments, including the stable pseudonym joining two rows.
- [ ] 5.2 Unit tests for the legal-hold refusal and the all-or-nothing failure.
- [ ] 5.3 A test asserting the anonymised value is absent from the search index and the history projection.
- [ ] 5.4 A test asserting the audit chain verifies after the re-seal and that the anonymisation entry is on it.
- [ ] 5.5 Deduplication check (ADR-012) recorded in the PR body.
