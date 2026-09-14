# Tasks: undo-a-bulk-action

## 1. What a job remembers

- [ ] 1.1 The per-member outcome records the changed properties with their prior values.
- [ ] 1.2 An instance ceiling bounds the stored prior values per job; a job above it is refused at creation.

## 2. Declaring reversibility

- [ ] 2.1 An action declares `reversible` and a reversal window, validated at schema save.
- [ ] 2.2 The preview reports whether the job will be reversible and for how long.

## 3. The inverse

- [ ] 3.1 A reversal route that creates a new job naming the original as its cause.
- [ ] 3.2 Members changed since the original are reported as not reversible and skipped.
- [ ] 3.3 A reversal outside the window, or of an irreversible action, is refused naming the reason.

## 4. Tests

- [ ] 4.1 Unit tests for the prior-value capture, the skip on a later edit, the window and the irreversible refusal.
- [ ] 4.2 An e2e over a reversed status change on a list surface.
- [ ] 4.3 Deduplication check (ADR-012) recorded in the PR body.
