# Tasks

## 1. The diff

- [ ] 1.1 `lib/Service/Flow/FlowGraphDiff.php` — compare two graphs and answer: removed nodes, removed edges, removed config keys on surviving nodes, and a MAJOR/MINOR verdict.
- [ ] 1.2 A config key removed from a node that also disappeared counts ONCE, under the node — see the trap.
- [ ] 1.3 Unit tests, each seen RED: removed node, removed edge, removed key, additions only, identical graphs, and the double-count case.

## 2. Storage

- [ ] 2.1 `semver` on `FlowVersion` and on `Flow`, plus a `semverSource` recording `derived` or `backfill`.
- [ ] 2.2 Migration for both columns. The ordinal and its unique index are untouched — D-1.
- [ ] 2.3 Bump `<version>` in `appinfo/info.xml` in the same commit, or the repair never runs (gate-110).

## 3. Deriving at publish

- [ ] 3.1 `FlowVersionService::publish()` derives from the diff against the PUBLISHED graph, not the previous ordinal — see the trap.
- [ ] 3.2 First publish is `1.0.0`. Patch stays `0` — D-4.
- [ ] 3.3 An explicit `major` is honoured; an explicit `minor` over a removal is REFUSED, naming what was removed — D-3.
- [ ] 3.4 An identical republish succeeds and is minor.
- [ ] 3.5 Tests seen RED for each of those, including the refusal's wording.

## 4. Telling the author first

- [ ] 4.1 The publish preflight reports the component it will bump and, when major, what was removed.
- [ ] 4.2 Test that a major preflight names the removed step AND the removed edge.

## 5. The back-fill

- [ ] 5.1 A repair stamping published versions per flow in ordinal order, marked `backfill`.
- [ ] 5.2 It must not fail an upgrade — D-5.
- [ ] 5.3 Test with a flow whose history has a gap.

## 6. The UI (nextcloud-vue)

- [ ] 6.1 The version pill shows the semantic version where one exists, and the ordinal where it does not.
- [ ] 6.2 A back-filled version is distinguishable from a derived one, so it can be distrusted correctly.
- [ ] 6.3 jest seen RED first; en and nl in place.

## 7. Proof

- [ ] 7.1 Playwright over the live API: publish a flow, remove a step, publish again, assert the major bump and the named removal.
- [ ] 7.2 Playwright: an explicit minor over a removal is refused.
- [ ] 7.3 `composer check:strict`, both l10n gates, full unit suite. Exit code, not summary line.
