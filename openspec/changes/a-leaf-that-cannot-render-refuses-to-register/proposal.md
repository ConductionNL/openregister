---
kind: code
---

## Why

A leaf has two halves: a descriptor the server registers, and a bundle the
browser loads. Only the first was checked. So a render-surface descriptor from an
app that ships no leaf bundle reached capability discovery, `getLeaves()` returned
it, gate-24 went green on both halves, and **the surface rendered nothing on every
consuming page**. Nobody was told, because nothing had failed.

`LeafScriptListener` already documents this: it names `humaniq-hours` as having
been dark on dossiq case pages for as long as leaves have shipped.

Measured on the development instance while writing this change, across **35
installed apps**:

| | count |
|---|---|
| apps registering a leaf | 6 (including openregister's built-ins) |
| apps declaring a **render surface** | 5 |
| apps shipping `js/<app>-leaves.js` | **2** (humaniq, planninq) |
| **render surfaces dark today** | **3** (buildiq, decidiq, hermiq) |

One of the three is worth its own sentence. **hermiq did build a leaf bundle** and
named it `js/hermiq-agent-leaf.js`. The loader looks for `js/hermiq-leaves.js`, so
that artifact is never read. The work was done and the filename made it invisible,
which is why the refusal message names the exact file the app must produce.

## What Changes

- `LeafRegistry` refuses to register a **render-surface** leaf whose providing app
  ships no leaf bundle, at `error` level, naming the leaf, the app and the file it
  must build.
- `LeafBundle` becomes the **one** answer to "can this app's leaf render", used by
  both the registry and `LeafScriptListener`. Two copies would drift, and the
  registry accepting a leaf the loader never puts on a page is exactly the failure
  being fixed.

**Three cases are deliberately not refused**, and each would be a regression:

- a leaf with **no render-surface kind**: a data provider or agent runner has no
  client half, so a bundle is not its contract;
- a **built-in** leaf, whose `requiredApp` is null: it rides OpenRegister's own
  bundle, already on the page;
- a leaf whose app is **disabled** or unresolvable. `describeForCapabilities()`
  already reports those as `usable: false`, which is right, because enabling the
  app fixes it. A missing bundle never fixes itself without a rebuild. Overriding
  the existing mechanism would be a second answer to "can this leaf be used".

## Capabilities

### Modified Capabilities

- `leaf-provider-registration`: registration refuses a render surface that cannot
  be rendered, rather than accepting it and reporting success.
