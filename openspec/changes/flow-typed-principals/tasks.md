# Tasks

## 1. The reference and the registry

- [ ] 1.1 `lib/Service/Flow/Principal/PrincipalReference.php` — the `{type, id}` value object, with a `from()` that reads a bare string as `{type: 'user', id: …}` and a list reader that accepts mixed types.
- [ ] 1.2 `lib/Service/Flow/Principal/IPrincipalResolver.php` — `type(): string` and `resolve(string $id): array<string>` returning user ids.
- [ ] 1.3 `lib/Service/Flow/Principal/RegisterPrincipalResolversEvent.php` — modelled on `RegisterFlowNodesEvent`.
- [ ] 1.4 `lib/Service/Flow/Principal/PrincipalResolverRegistry.php` — collects resolvers on first use, answers `has(type)` and `resolve(reference)`. No cross-request cache: D-1, and the late-resolution decision above it.
- [ ] 1.5 `UserPrincipalResolver` and `GroupPrincipalResolver`, registered by openregister itself.
- [ ] 1.6 Unit tests, each seen RED first: a bare string reads as a user; a list mixes types; an unregistered type answers `has() === false`; a group resolves to its current members and re-resolves after a membership change.

## 2. Authorisation

- [ ] 2.1 `FlowRunAssignee::mayAnswer()` consults the registry instead of comparing strings. Keep the null-assignee branch exactly as it is.
- [ ] 2.2 The task service's answer guard takes the same path, so the two cannot disagree.
- [ ] 2.3 Record the resolution at creation as evidence only. Assert in a test that the guard does **not** read it — a former member must be refused while still appearing in the record.
- [ ] 2.4 Check every caller of `mayAnswer` for the hot-path trap: inbox listing must still predicate in the datastore, never resolve row by row.
- [ ] 2.5 Tests: a position authorises; a coincidental group name no longer authorises a `user` reference; an unassigned task is unchanged.

## 3. The node

- [ ] 3.1 `UserTaskConfig` parses typed references on `assignee`, `candidates`, `routingFallback`; reads the three legacy candidate fields as typed candidates.
- [ ] 3.2 `validateConfig` refuses an unknown type, naming type and field. It does NOT resolve — D-2.
- [ ] 3.3 Task creation resolves; an empty resolution fails the step, distinguishing "no such principal" from "resolved to no users".
- [ ] 3.4 Declare `principal` as the field type on every performer field; drop `performerType` from the form and derive the column from the reference.
- [ ] 3.5 Display name to "Ask a person or group"; description reviewed against the `writing` skill before it is written.
- [ ] 3.6 Tests: legacy `candidateGroups` still configures a step; an unknown type is refused at save; an empty group fails at creation and not at save.

## 4. Agent as a performer

- [ ] 4.1 Accept `{type: 'agent', id}` with a `prompt` in place of a form.
- [ ] 4.2 Dispatch `AgentRunRequestedEvent` rather than invoking a runtime; the completing app uses the ordinary verbs.
- [ ] 4.3 An `agent` resolver whose identity is **not** a member of human groups — see the trap; assert it.
- [ ] 4.4 Test: an unanswered agent task reassigned to a person is answerable by that person, and the reassignment is on the audit.

## 5. The picker (nextcloud-vue)

- [ ] 5.1 A `principal` widget in `CnFlowNodeEditModal.vue`: multi-select, searches as you type against `/ocs/v2.php/core/autocomplete/get` with `shareTypes[]=0,1`.
- [ ] 5.2 A stored reference the current user cannot see must still SHOW, the way the existing `user` widget synthesises an unseen uid — otherwise a save silently clears a delegation.
- [ ] 5.3 App-contributed types are offered from a server-declared option source, not from a list the editor hard-codes.
- [ ] 5.4 `runAs` keeps the existing single-user widget.
- [ ] 5.5 jest specs seen RED first; en/nl catalogues for every new string, `writing` skill loaded first.

## 6. The repair

- [ ] 6.1 A repair step rewriting unambiguous strings on tasks and on flow definitions.
- [ ] 6.2 Ambiguous and unresolvable strings are left alone and reported, naming the carrier and the reason. **Must not fail the upgrade** — D-4.
- [ ] 6.3 Bump `<version>` in `appinfo/info.xml` in the same commit, or the step never runs (gate-110).
- [ ] 6.4 Test with a fixture holding one of each: unambiguous, ambiguous, unresolvable.

## 7. Proof

- [ ] 7.1 Playwright, over the live API: author a step naming a group, run it, confirm a member can answer and a non-member cannot.
- [ ] 7.2 Playwright: a step naming a group that does not exist fails the step and leaves no suspended run — the measured defect, inverted into a test.
- [ ] 7.3 Playwright: an unknown type is refused when the flow is saved.
- [ ] 7.4 `composer check:strict`, both l10n gates, and the full unit suite. Read the exit code, not the summary line.

## 8. Afterwards, not here

- [ ] 8.1 decidiq contributes `position`; hermiq contributes `function`; dossiq contributes `role`. Separate PRs in their own repos.
- [ ] 8.2 Retiring `dossiq.askPerson` onto this node — its own change, once the resolvers exist.
