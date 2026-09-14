# Tasks: delete-window-and-recorded-destruction

## 1. The stated window

- [ ] 1.1 The soft-deleted object publishes its destroyable-from date and days remaining, on the object, in the trash listing and in the refusal a reader gets (D-2).
- [ ] 1.2 Restore before that date stays one act and is recorded with the actor.

## 2. Destruction as a named act

- [ ] 2.1 Destroying requires a declared right from the permission catalogue; the refusal names the rule (D-3).
- [ ] 2.2 The act records actor, time, scope and the rule it ran under, and that record survives the object (D-4).

## 3. The declared scope

- [ ] 3.1 A schema declares what is destroyed with the object: versions, notes, files, tasks, timeline entries and content-bearing audit rows (D-4).
- [ ] 3.2 The act previews the scope with counts before it runs and reports what was destroyed after.
- [ ] 3.3 The evidence of destruction is excluded from the scope by construction, proven by a test.

## 4. Two clocks

- [ ] 4.1 An object carries its AVG date and its Archiefwet date, each with the rule that produced it (D-5).
- [ ] 4.2 Where the two disagree the object is not destroyed and the conflict is reported for a decision (D-5).
- [ ] 4.3 A legal hold exempts an object from both clocks (D-6).

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/delete-window.spec.ts`: delete an object, read the window, restore it, delete it again, destroy it with the right and read the destruction record.
- [ ] 5.2 Unit tests: the published window, the refused destroy without the right, the declared scope and its preview, the surviving evidence, the two clocks and the conflict, and the hold over both.
- [ ] 5.3 A regression test that an instance declaring no scope destroys exactly what it destroys today.
- [ ] 5.4 `openspec validate delete-window-and-recorded-destruction --strict`.

## 6. Hand over

- [ ] 6.1 Tell the dossiq lane that `case-delete-guard` is unchanged and now refuses into a published window, with the cluster 39 candidate ids.
