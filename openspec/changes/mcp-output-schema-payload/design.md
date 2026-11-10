## Context

`tools/list` returns every registered tool's full descriptor, and the model receives
that on every turn. Measured 2026-08-16: 122 tools, 433,198 bytes, ~108,300 tokens —
**54% of a 200K context window before the user says anything**.

`outputSchema` was 79.7% of it, because `buildOutputSchema()` inlined
`$schema->getProperties()` in full for both read verbs.

## Goals / Non-Goals

**Goals:**
- Cut the per-turn token cost of tool definitions.
- Keep the response-shape information the model actually uses.
- Make a regression fail a test rather than silently double every prompt.

**Non-Goals:**
- The handshake latency. Measured after this change it is still ~2.1 s; see D4.
- `inputSchema`. It is 14% of the payload and it is what the model needs to CALL a
  tool correctly. Trading that for bytes would trade correctness for speed.
- Per-agent tool scoping, and caching. Both measured, both deliberately separate.

## Decisions

### D1 — Envelope, not item

`search` keeps `{results, total, hasMore}` with `results.items` reduced to
`{"type": "object"}`; `get` becomes `{"type": "object"}`.

The item's properties are redundant: the model reads the actual result when the tool
returns. The envelope is not — it tells the model a `search` yields a wrapper rather
than a bare array.

### D2 — Keep the envelope rather than drop `outputSchema` entirely

Measured, on the same 122 tools:

```
drop outputSchema entirely   96,466 B
keep the envelope           103,154 B    (+6,688 B, +1.5%)
```

1.5% is a cheap price for the model knowing the response shape. Dropping it would
also silently break any client that reads `outputSchema` for structured content.

### D3 — `buildOutputSchema()` no longer takes a `Schema`

The parameter became unused. Removing it is not tidiness: leaving a `Schema` in the
signature tells the next reader that this method legitimately depends on the
schema's properties, which is exactly the assumption that would re-introduce the
inlining.

### D4 — The latency lever is a different change

After deploying, the handshake fell only ~20-25% against a 76% payload cut. The
remaining ~2 s is schema enumeration and descriptor construction, not byte
serialisation. Caching `tools/list` would address it and carries its own
invalidation question — when a schema changes, when an app is enabled — which
deserves its own spec rather than being smuggled in here.

## Seed Data (ADR-001)

**None.** No schemas are introduced or modified. The change alters how existing
schemas are *described* to an MCP client.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Tool descriptor derivation | **Imperative** | Existing derivation code in OR's MCP layer; not a lifecycle, aggregation, derived field, notification, relation or widget. |

## Risks / Trade-offs

**A client validating structured content per-property loses that.** Nothing in this
fleet does — hermiq's runner passes tool definitions through to the CLI unchanged.
This is the one real behavioural risk and it is called out rather than buried.

**A byte budget can be gamed by raising the ceiling.** The test states the measured
size in its failure message so the next person sees what they are agreeing to, and
the ceiling is set against a fixture with 25 wordy properties — comfortably above the
envelope form (~900 B) and far below the inlined form.

**The test targets `search` + `get` only.** `create`/`update` inline the property set
into their INPUT schema, legitimately — you cannot create an object without being
told its properties. Measured at ~7.5 kB each on the fixture, they would swamp the
signal. That exclusion is stated in the test itself so it cannot be mistaken for an
oversight.
