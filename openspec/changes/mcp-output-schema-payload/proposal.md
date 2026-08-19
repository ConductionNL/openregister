---
kind: code
---

## Why

**Tool schemas consume 54% of a 200K context window before the user says anything**,
and the payload is re-sent on every turn.

Measured on the shared development instance 2026-08-16, via
`tools/list` against `/apps/openregister/api/mcp`:

```
122 tools
433,198 bytes of JSON
≈ 108,299 tokens   ->  54% of a 200K context window
```

### One field is 80% of it

| Field | Bytes | Share |
|---|---|---|
| **`outputSchema`** | **335,580** | **79.7%** |
| `inputSchema` | 59,022 | 14.0% |
| `description` | 19,472 | 4.6% |
| everything else | ~7,000 | 1.7% |

Only 64 of the 122 tools carry an `outputSchema` at all, so 80% of the payload comes
from just over half the tools.

### The cause is an asymmetry in this class

`SchemaDerivedToolProvider::buildOutputSchema()` inlines the schema's **entire**
property set:

```php
$itemSchema = ['type' => 'object', 'properties' => ($schema->getProperties() ?? [])];
```

Its input counterpart does the opposite. `buildInputSchema()` for a `search` verb
calls `filterProperties()` and narrows to the filters the dialect actually declared
(REQ-DERIVED-004). The input path was designed to be economical; the output path was
not, and nothing made the difference visible.

The result on the worst tools:

```
38,561 B  shillinq.ARInvoice.search   ->  36,293 B of it is outputSchema (94%)
36,505 B  shillinq.ARInvoice.get
```

`ARInvoice.search` costs **~9,600 tokens by itself** — more than the entire `hermiq`
tool set (22 tools, 12,751 B). Its `inputSchema` is 1,915 B.

### Why this is worth doing now

Nextcloud's own request costs ~46 ms. The MCP handshake costs **~2.6 s**
(`initialize` 1221–1651 ms, `tools/list` 1199–1417 ms), and the model then works
through a prompt that is majority tool schema. Every agent turn pays it.

## What Changes

**`buildOutputSchema()` emits the ENVELOPE, not the item's properties.**

- `search` keeps `{results: array, total: integer, hasMore: boolean}`, with `results.items`
  reduced to `{"type": "object"}`.
- `get` becomes `{"type": "object"}`.

Measured effect on the same 122 tools:

| | Bytes | Tokens | Context |
|---|---|---|---|
| current | 433,198 | 108,299 | 54% |
| **after** | **103,154** | **25,788** | **13%** |

**A 76% reduction.**

### Measured on the live instance after deploying

```
payload      411,561 B  ->  97,479 B     -76%   (~108,300 -> ~24,400 tokens)
context use       54%   ->      12%
initialize   1221-1651 ms -> 992-1184 ms  -20%
tools/list   1199-1417 ms -> 958-988 ms   -25%
```

**The latency did NOT fall proportionally, and that matters.** A 76% smaller payload
bought only ~20-25% off the handshake, which means the ~2 s that remains is **not
serialisation-bound** — it is the work of enumerating schemas and building
descriptors, not of writing bytes to a socket.

So this change succeeds at what it is for — context budget and the size of every
prompt the model reads — and **does not bring the handshake within the 250 ms
budget**. The handshake is still ~2.1 s, roughly 8x over. Closing that is the
caching lever, which is deliberately a separate change (see below), and anyone
citing this one as a latency fix would be overstating it.

### Why the envelope is kept rather than dropping `outputSchema` entirely

Dropping it outright measures 96,466 B — only 6,688 B (1.5%) better than keeping the
envelope. For that 1.5% the model would lose the fact that a `search` returns
`{results, total, hasMore}` rather than a bare array, which is exactly the kind of
shape knowledge that stops it guessing. The envelope is cheap and load-bearing; the
per-property inlining is expensive and redundant, because the model reads the actual
result at call time.

## Capabilities

### Modified Capabilities
- `mcp-tool-derivation`: `outputSchema` describes the response envelope, not every
  property of the underlying schema.

## Impact

- **Code**: `lib/Mcp/BuiltIn/SchemaDerivedToolProvider.php::buildOutputSchema()`.
- **Consumers**: any client relying on `outputSchema` to validate structured content
  per-property loses that. Nothing in this fleet does — hermiq's runner passes tools
  through to the CLI unchanged. Called out because it is the one real risk.
- **Not changed**: `inputSchema`. It is 14% of the payload and it is what the model
  needs in order to *call* the tool correctly. Trimming it would trade correctness
  for bytes.

## Explicitly not in this change

Two further levers were measured and are deliberately separate, because each needs
its own argument and its own numbers:

1. **Scope tools per agent.** A docudesk-only agent would carry 12% of the current
   payload. That is a hermiq concern (agent configuration), not a derivation
   concern.
2. **Cache the handshake.** `initialize` + `tools/list` is ~2.6 s and the registry
   does not change between turns. Caching is a separate change with its own
   invalidation question.
