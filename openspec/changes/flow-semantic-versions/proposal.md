---
kind: code
depends_on: []
---

## Why

A flow's version is a counter. `v1`, `v2`, `v3` — one more each time somebody opens a draft, and it says nothing about what changed. An operator looking at `v7` cannot tell whether it renamed a step's label or deleted the branch their integration depended on.

Both facts a version could carry are absent:

- **Nothing marks a breaking change.** Removing a step, removing an edge, or dropping a config key another step reads all produce exactly the same `+1` as adding a description. A consumer pinned to a flow has no signal to act on, so every publish is either ignored or treated as dangerous.
- **The number bumps at the wrong moment.** `FlowVersionService::createDraft()` takes `highestVersion() + 1` when a draft is *opened*. Whether the change is breaking cannot be known then, because the change has not been made yet. It is knowable at PUBLISH, where there is a previous published graph to compare against.

## What Changes

- **A published version carries a semantic version**, derived at publish by comparing the graph being published with the currently published one:
  - a **removed node**, a **removed edge**, or a **removed config key** on a surviving node is **MAJOR**
  - anything else — added nodes, added edges, changed labels, changed config values — is **MINOR**
  - the first publish of a flow is **1.0.0**
- **The ordinal stays.** `version` remains an integer, remains the unique key with `flow_uuid`, and remains what `FlowRun.flowVersion` pins. Semver is an ADDITIONAL, author-facing fact. Replacing the ordinal would mean migrating two unique indexes, every run row's pin and 28 call sites to buy a label — and would put the run pin at the mercy of a derivation.
- **The author is told before they publish.** The publish confirmation names the version it is about to create and why it is major, listing what was removed. A derivation nobody can see before it fires is one nobody trusts.
- **An author may raise it, never lower it.** Publish accepts an explicit `major`, so an author who knows a value change breaks a consumer can say so. It refuses a request to publish a major change as minor: the diff is evidence and the author's optimism is not.
- **Patch is not derived.** Nothing in a graph distinguishes a fix from a feature, so a derived patch would be a guess wearing three digits. The third component stays `0` until something can honestly set it.

## Capabilities

### New Capabilities
- `flow-semantic-versions`: what a flow's semantic version means, when it is derived, which differences are breaking, and how an author may override it.

### Modified Capabilities
- `flow-definition-versioning`: publishing derives and stores a semantic version alongside the ordinal; the ordinal's meaning is unchanged.

## Impact

| Area | Change |
|---|---|
| `lib/Db/FlowVersion.php` | `semver` column |
| `lib/Db/Flow.php` | `semver` column, mirroring the head's |
| `lib/Migration/` | both columns, plus a repair stamping existing published versions |
| `lib/Service/Flow/FlowGraphDiff.php` | new — what changed between two graphs, and whether it breaks |
| `lib/Service/Flow/FlowVersionService.php` | derives at publish, accepts an explicit raise |
| `lib/Controller/FlowController.php` | the diff verdict on the publish preflight |
| nextcloud-vue | the pill shows the semantic version where one exists |

## Out of scope, deliberately

- **Replacing the ordinal.** See above: two unique indexes, the run pin and 28 call sites, to buy a label.
- **Deriving a patch level.** A graph cannot distinguish a fix from a feature.
- **Consumers acting on the version.** Nothing subscribes to a flow's version today. Emitting a signal nobody reads would be inventing a contract; this change makes the fact true first.
