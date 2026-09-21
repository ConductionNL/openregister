---
kind: code
---

## Why

openregister#3954 refused a render-surface leaf whose app shipped no
`js/<app>-leaves.js`. It was wrong twice in one measurement: hermiq and decidiq
ship no such file and are not dark, because each loads its own registration
bundle on **every page** with `Util::addInitScript`. #3955 downgraded the refusal
to a report before it broke them.

The rule that came out of it: **refusing on filesystem evidence is unsound,
because whether a bundle reaches the page is a fact about the page, and the
registry sees only the filesystem.**

This finishes the thought. A descriptor says which convention it uses, and the
refusal judges a **claim** rather than an inference.

## What Changes

`LeafDescriptor` gains `loadStrategy`, one of three:

| strategy | meaning | verifiable |
|---|---|---|
| `shared-entry` | the app builds `js/<app>-leaves.js` and the platform loads it | **yes** |
| `own-script` | the app loads its own bundle, typically `Util::addInitScript` | no, taken on the app's word |
| `already-present` | a built-in leaf riding OpenRegister's own bundle | nothing to load |

`LeafRegistry` refuses **only** a leaf that claims `shared-entry` and whose app
ships no such file. That is provable: the platform does that loading, so it can
see the file is absent.

**Silence is not a claim.** A descriptor that declares nothing is reported and
registered, exactly as #3955 left it. Every descriptor written before this
existed says nothing, and refusing silence would re-create the #3954 failure
wholesale.

**If a fourth convention appears, it gets its own name.** `own-script` means "the
app guarantees it"; a genuinely different mechanism the platform could verify
deserves a name of its own, because the value of this list is that one entry is
checkable and the others are trusted.

## Capabilities

### Modified Capabilities

- `leaf-provider-registration`: a leaf may declare how its bundle reaches the
  page, and a claim of the shared entry is verified.
