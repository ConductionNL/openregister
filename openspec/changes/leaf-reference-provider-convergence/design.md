# Design: leaf-reference-provider-convergence

## Purpose

Answer the ADR-041/ADR-066 open question with evidence: does the OpenRegister
`app-local` cross-app leaf mechanism converge onto, coexist with, or bridge to
Nextcloud's native `IReferenceProvider`? This document is the substance — the
capability comparison, the argued options, the recommendation, and the
author-facing boundary rule.

## The two mechanisms, precisely

### OpenRegister leaf (data-provider, `app-local`)

Established by the merged `app-leaf-provider-registration` change. A sibling app
registers one `IEventListener` on `RegisterLeafProvidersEvent` and calls
`registerLeaf(LeafDescriptor, ?IntegrationProvider)`. A `data-provider` leaf's
`IntegrationProvider` with `getStorageStrategy() === 'app-local'` exposes, keyed
on `(register, schema, objectId)`:

- `list(register, schema, objectId, filters)` — returns a **collection** of the
  app's own linked items for that object, in the flat-list or
  `items / total / nextCursor` envelope shape.
- optional `create(register, schema, objectId, ...)` — **appends** an item (the
  canonical case: a note) to the app's own store; a read-only leaf lets it throw
  not-implemented.

The provider runs in the **contributing app's DI context** (its listener
constructed it there). A `render-surface` leaf additionally mounts a tab + a
widget through the JS `registerIntegration()` path, correlated by shared `id`,
with tab+widget **parity** required (ADR-019, gate-24). Discovery is
server-side: `LeafDescriptor::toArray()` flows through the OCS capability
`openregister.integrations.leaves` (`IntegrationsCapability` →
`LeafRegistry::describeForCapabilities()`) and is enumerable **without loading
any app's JS**. The boundary is render-and-read only (ADR-066): no verb, no
command, no handler.

### Nextcloud `IReferenceProvider`

`OCP\Collaboration\Reference\IReferenceProvider` (since NC 25). An app registers
a provider class at boot via
`IRegistrationContext::registerReferenceProvider($class)` — the **core owns the
registry** so the idiom is a registration-context call, not an event. Keyed on a
**reference string** (typically a URL):

- `matchReference(referenceText)` — does this provider own this string.
- `resolveReference(referenceText)` — returns **one** `IReference` (or null): a
  single rich preview with title, description, image, URL, and a typed
  rich-object / OpenGraph payload. **Read-only** — `IReference` has no mutation.
- `getCachePrefix` / `getCacheKey` — per-reference, optionally per-user, caching
  in `ReferenceManager`.

`IDiscoverableReferenceProvider` adds picker metadata (`getId`, `getTitle`,
`getOrder`, `getIconUrl`) so the provider appears in the smart-picker;
`IReferenceManager::getDiscoverableProviders()` enumerates those. The render
surface is an **inline rich-object preview** embedded in free text (a Talk
message, a Text document), rendered by a reference widget — not a tab on an
object.

## Capability comparison

| Dimension | app-local data-provider leaf | `IReferenceProvider` |
|---|---|---|
| **Keys on** | an OR object: `(register, schema, objectId)` | a reference string / URL, matched by `matchReference()` |
| **Read cardinality** | **collection** — `list()` returns many linked items scoped to the object | **single** — `resolveReference()` returns exactly one `IReference` per id |
| **Object-collection scoping** | native; the entire purpose ("all the app's notes on *this* object") | absent; a reference is one thing, with no "all X on object Y" model |
| **Write / append** | optional `create()` appends an item to the app's own store | **none** — read-only by contract; `IReference` exposes no mutation |
| **Render surface** | tab + widget on the object (parity required), plus optional single-entity widget via `referenceType` | inline rich-object / OpenGraph preview inside text; smart-picker entry when discoverable |
| **Discovery** | server-side `LeafDescriptor` via OCS capability `openregister.integrations.leaves`; enumerable without JS | `getDiscoverableProviders()` for discoverable ones; resolution is a per-reference `matchReference()` scan |
| **RBAC / tenant scoping** | provider runs in app DI context; `requiresPermission`; OR object RBAC + the app's own store access | `setAccessible()` per `IReference`; per-user `getCacheKey`; access decided by the provider |
| **Caching** | none mandated; `list()` hits the app store each call | built-in metadata cache keyed by prefix + key |
| **Cross-app registration** | typed collect-event `RegisterLeafProvidersEvent` (OR is an **app** → event idiom) | `IRegistrationContext::registerReferenceProvider()` at boot (**core** owns registry → context idiom) |
| **Contract shape** | list + optional linked-item append, no verb | match + resolve, read-only |

### Where they overlap

Exactly **one** narrow place: rendering a **single, read-only** linked entity
identified by a URL / id. That is the leaf's *single-entity* render surface (the
`referenceType` marker on a schema property, AD-18) versus a reference's
rich-object preview. Both can turn "a URL to one thing in another app" into "a
small read-only card." This is the only geometric intersection.

### Where they are genuinely different

Everything else, and the differences are structural, not cosmetic:

1. **Cardinality + scoping.** A leaf answers "give me all of app X's items *on
   this object*." `IReferenceProvider` answers "resolve *this one* string." There
   is no object-collection concept in the reference contract — `matchReference`
   takes a string, not an object identity, and `resolveReference` returns one
   `IReference`.
2. **Write / append.** A leaf may `create()` (append a note). `IReference` is
   read-only end to end; there is no append in the reference machinery.
3. **Render surface + parity.** A leaf is a tab+widget on an object detail page
   under an ADR-019 parity rule enforced by gate-24. A reference is an inline
   preview in free text. Different surfaces, different discovery, different
   lifecycle.

## The options

### (a) CONVERGE — app-local read leaves become `IReferenceProvider` implementations

**Argument for:** reuse NC's cross-app machinery (registration, caching,
smart-picker), one fewer bespoke layer, no drift.

**Why it fails.** Two dealbreakers, both structural:

- **Collection scoping.** Even a *pure read-only* app-local leaf returns a
  collection keyed on an OR object. `IReferenceProvider` has no way to express
  "all the notes on object Y" — it matches one string and resolves one
  `IReference`. Converging would mean either abandoning the object-collection
  read (the whole point of a data-provider leaf) or bolting a collection
  protocol onto a single-reference contract, which is a fork, not convergence.
- **Write / append.** `create()` (append a note) has no home in the reference
  contract at all. A converged read leaf would still need a second, non-reference
  path for its append — so convergence does not even remove the bespoke layer; it
  splits one leaf across two mechanisms.

Convergence is therefore rejected: the read subset does not map (collection vs
single), and the write subset cannot map (no mutation in `IReference`).

### (b) COEXIST — documented boundary (RECOMMENDED)

**Argument for:** the two mechanisms already exist and answer different
questions. Draw one clear author-facing line and both keep their strengths — the
leaf its object-scoped collection + append + tab/widget parity, the reference
its inline URL-preview reach across Talk/Text/etc. No code, no migration, no
drift risk *provided the boundary is written down and enforced by review*.

**Cost:** two mechanisms in the fleet. That is acceptable because they are not
*redundant* — the overlap is a single narrow render case, and the boundary rule
(below) assigns even that case deterministically.

This is the recommendation.

### (c) BRIDGE — a thin adapter so an `IReferenceProvider` surfaces AS a leaf

**Argument for:** an app that already ships an `IReferenceProvider` for its
entity URLs could, via an adapter, have OR render that reference as a leaf's
*single-entity* widget without writing a second provider — exploiting the one
overlap point.

**Why not now.** It is genuinely plausible but speculative: no current driver
needs it, it adds a second way to register a render surface (a discoverability
and parity headache — which mechanism owns the tab+widget?), and it only ever
covers the single-entity read case, never the collection or the append. Building
it now would trade a clean boundary for an adapter nobody has asked for. Recorded
as a **named future option, not adopted.** If a concrete driver appears (an app
with an existing reference provider wanting a single-entity OR widget for free),
revisit it then, scoped strictly to single-entity read-only render.

## Recommendation

**COEXIST with a documented boundary.** Reject convergence (collection-scoping
and append are unmappable onto `IReferenceProvider`). Do not build a bridge now
(no driver; keep one clean registration path), but name it as the only sensible
future convergence and scope it to single-entity read-only render.

## Author-facing boundary rule

> **Use a data-provider leaf when** you are surfacing an **object-scoped
> collection** of your app's own items on an OpenRegister object (list, and
> optionally append) — "all of app X's notes/records on *this* object" — and/or a
> tab/widget on the object.
>
> **Use an `IReferenceProvider` when** you are turning a **single URL or text
> token** into a **read-only rich preview** embedded in free text (Talk, Text,
> comments), with no object-collection and no write.
>
> **Never** use a data-provider leaf for a stateless URL/text preview that has no
> OR object and no collection — that is an `IReferenceProvider`. **Never** use an
> `IReferenceProvider` to expose an object-scoped collection or any append — that
> is a leaf.

The one overlap (single read-only linked entity by URL) resolves by context: if
it hangs off an OR object as that object's linked-entity render, it is a leaf's
single-entity surface; if it is an inline preview of a bare URL in text, it is a
reference. A future bridge, if built, would let the reference case *also* render
as the leaf single-entity surface — but until then, the two do not overlap in
practice.

## Risk: two ways to do one thing

The only redundancy risk is the single-entity render overlap. The boundary rule
above assigns it by context, and the normative requirement in
`specs/leaf-reference-boundary/spec.md` fixes the rule so a reviewer can cite it.
Because convergence is rejected on structural grounds (not preference), there is
no pressure to later collapse the two — they are load-bearing in different
places. Drift risk (ADR-066's concern) is mitigated by (1) writing the boundary
down and (2) keeping the bridge as the single sanctioned future convergence
point rather than letting ad-hoc adapters accrete.

## Recommended ADR-066 amendment

ADR-066 decision #6 currently reads *"IReferenceProvider convergence stays
deferred."* This change answers it. Recommended amendment (a status note on
ADR-066, mirroring how ADR-066 amended ADR-041):

> **Amended 2026-07-25 by openregister change
> `leaf-reference-provider-convergence`:** the convergence question is decided —
> **coexist with a documented boundary.** App-local read leaves do not converge
> onto `IReferenceProvider` (object-collection scoping and append are
> unmappable). A single-entity read-only **bridge** is named as the only future
> convergence point but is **not** built. See that change's `design.md` for the
> comparison, rationale, and author boundary rule.

The amendment is **warranted** — decision #6 was explicitly held open pending
"a separate future investigation," and this is it. It is a documentation note
(no ADR-066 decision is reversed; #6 moves from *deferred* to *decided: coexist*,
consistent with #6's own leaning that the bespoke OR layer is justified).

## References

- ADR-041 — Cross-App Commands via Events; the Integration-Registry /
  Reference-Provider Boundary (the deferred convergence note).
- ADR-066 — Cross-app leaf registration (decision #6 keeps convergence open).
- ADR-019 — Integration registry / leaf system (tab+widget parity).
- Merged: openregister `app-leaf-provider-registration` — `LeafDescriptor`,
  `RegisterLeafProvidersEvent`, `app-local` strategy, `openregister.integrations.leaves`.
- `OCP\Collaboration\Reference\IReferenceProvider`, `IReference`,
  `IDiscoverableReferenceProvider`, `IReferenceManager`,
  `IRegistrationContext::registerReferenceProvider()`.
