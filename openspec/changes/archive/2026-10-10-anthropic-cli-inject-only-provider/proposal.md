---
kind: config
depends_on: []
---

## Why

Hermiq needs to run a Claude Max/Pro subscription through the **official `claude` CLI** inside the
hardened `hermiq-llm-runner` ExApp. That is the Terms-of-Service-sanctioned way to use a subscription:
Anthropic **refuses** a subscription OAuth token on the direct Messages API — verified 2026-07-16, the
response is HTTP 429 `{"type":"error","error":{"type":"rate_limit_error","message":"Error"}}` carrying
`anthropic-organization-id` (so the token authenticates) but **no `retry-after` and no
`anthropic-ratelimit-*` counters at all**, byte-identical after 14 hours of zero usage. It is a
categorical refusal, not a quota. The only alternatives are (a) an API key on the existing host-locked
`anthropic` proxy entry, or (b) the official CLI. This change enables (b).

A CLI needs its token in the process **environment**. There is no outbound request for the broker to
proxy and no header for `injectAuth()` to substitute into — so the constrained-proxy path cannot
express this credential **at any host**. Today `anthropic-oauth` is a host-locked *proxy* provider, and
`CredentialBrokerService::resolveInjectable()` returns `null` for those by design
(`lib/Service/Credential/CredentialBrokerService.php:277-279`), so its secret can never reach the calling
app. Correct for a proxy credential; fatal for a CLI. Hermiq therefore has no way to obtain the token.

`CredentialController::create()` rejects an unregistered provider outright
(`lib/Controller/CredentialController.php:267` — `$this->catalogue->get($provider) === null` → 400), so
without this catalogue entry a user cannot even *save* the credential. This change is the head of a
three-link chain (ADR-032) and **must merge before** Hermiq's manifest declares the provider.

## What Changes

One additive entry in the runtime-immutable catalogue, `lib/Settings/credential-providers.json`:

- **`anthropic-cli`** — `inject_only: true`, **no `baseUrl`**, **no `allowRules`**. Grouped with the other
  anthropic providers rather than with the `generic-*` block, because it is an anthropic provider; its
  `inject_only` treatment is keyed off the flag, not its position.
- `version` bumped `1.5.0` → `1.6.0`. (Note: `1.5.0` was taken by commit `6473d7e37`, which added the
  `anthropic`/`anthropic-oauth` descriptors — the bump is from the *actual* current version, not from
  `1.4.0` as the `$injectOnlyComment` precedent might suggest.)
- `$injectOnlyComment` extended so `inject_only` is no longer read as "the five `generic-*` entries".
  It now covers **two** distinct reasons the broker cannot bound a call: an **unbounded host** (the
  `generic-*` case) and a **non-HTTP consumer** (this case). Without this, a reader reasonably infers
  `inject_only ⊆ generic-*` and misreads the new entry.

**No PHP changes.** The `inject_only` branches already exist and are keyed purely on the flag:
`request()` denies (`:201-203`, *"inject-only provider cannot be proxied; use resolveInjectable"*) and
`resolveInjectable()` releases only for inject-only (`:277-279`) — both strictly **after** Guard 1
(owner/IDOR) and Guard 2 (`allowedApps`). The new entry inherits correct handling for free.

## Capabilities

### New Capabilities
<!-- none — this extends an existing capability -->

### Modified Capabilities
- `credential-broker`: the provider catalogue gains an `inject_only` entry whose justification is a
  **non-HTTP consumer** rather than an unbounded host, and the personal-scope-only Terms-of-Service
  constraint that binds a subscription credential is recorded normatively.

## Impact

**Files**: `lib/Settings/credential-providers.json` (one entry, one version bump, one comment).

**Security — a deliberate, bounded trade-off that must not be read as an oversight.** An `inject_only`
credential's secret **leaves OpenRegister** into the trusted same-instance calling app. This weakens the
broker's "the app never sees the secret" property. It is:
- **forced** — a CLI needs the token in its env; there is no proxy seam to hide behind;
- **precedented** — the five `generic-*` entries already make exactly this trade (v1.4.0);
- **bounded** — Guard 1 (owner/IDOR) and Guard 2 (`allowedApps`) both still run before release, Doriath
  keeps custody, and the app's config holds only a `credentialRef`, never the secret;
- **narrower than the alternative** — the secret would otherwise sit in app config in cleartext.

Prefer the host-locked `anthropic` proxy entry wherever it works: it is zero-knowledge and strictly
stronger. `anthropic-cli` is for the case the proxy provably cannot serve.

**Terms of Service**: a Claude Max/Pro subscription serves **its own subscriber**. This credential is
**personal-scope only** — it must be rejected at organisation scope and may serve only its owner.
This change *declares* that constraint; it is **enforced** by chain link 2
(`cli-runner-text-turn-dispatch`, hermiq), which owns the only resolution path. A `kind: config` change
has no resolution path and so cannot enforce it. That gap is inert until link 2 lands: without link 2
there is no `cli` dispatch, so nothing can use the credential at all.

**Consumers**: none today. Hermiq's manifest declaration (`cli-runner-credential-declaration`, PR 2) and
the dispatch that calls `resolveInjectable()` (`cli-runner-text-turn-dispatch`) both land after this.

**No API surface change**: `GET /api/credentials/providers` and `POST /api/credentials` gain a new data
*value*, not a new shape. `ProviderCatalogue::get()` is an exact key lookup.
