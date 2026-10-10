## Context

The credential broker has two worlds, and the catalogue keeps them apart:

- **Host-locked proxy providers** carry a `baseUrl` + `allowRules`. OpenRegister makes the call itself
  and substitutes `{secret}` into one header. The app never sees the secret — zero-knowledge.
- **Inject-only providers** (`inject_only: true`, added v1.4.0) carry neither. `request()` refuses them
  (`CredentialBrokerService.php:201-203`) and `resolveInjectable()` hands the raw secret to the
  same-instance calling app after two guards (`:277-279`).

The five existing inject-only entries are all `generic-*`, and they exist for **one** reason:
OpenConnector's arbitrary/self-hosted Sources cannot be host-locked from an immutable file, so the
broker cannot bound the *host*.

This change introduces the first inject-only provider that exists for a **different** reason. Hermiq must
run a Claude Max/Pro subscription through the official `claude` CLI in the hardened `hermiq-llm-runner`
ExApp — the ToS-sanctioned path, since Anthropic categorically refuses a subscription OAuth token on the
direct Messages API (verified 2026-07-16: HTTP 429 `rate_limit_error` with `anthropic-organization-id`
present but **zero** rate-limit counters and no `retry-after`, unchanged after 14h idle — a refusal, not
a quota). A CLI reads its token from the process **environment**. The host is perfectly well known
(`api.anthropic.com`); what is missing is a *request to proxy at all*.

## Goals / Non-Goals

**Goals:**

- Register `anthropic-cli` so a user can save the credential — `CredentialController::create()` rejects an
  unregistered provider with a 400 (`:267`), so nothing downstream is possible without this.
- Generalise what `inject_only` means, in the file itself, so the next reader does not infer
  `inject_only ⊆ generic-*` and misread the entry.
- Record the personal-scope-only ToS constraint normatively.
- Keep the change purely declarative — no PHP.

**Non-Goals:**

- Enforcing personal scope. A catalogue entry has no resolution path; enforcement is chain link 2's.
- Any consumer. Hermiq's manifest and dispatch land in later links.
- Weakening the proxy path. `anthropic`/`anthropic-oauth` stay byte-for-byte unchanged.
- A `grok-cli` / `openai-cli` sibling. Those CLI contracts are unverified; this chain exists precisely
  because an unverified CLI assumption shipped once already.

## Decisions

**D1 — `inject_only: true`, no `baseUrl`, no `allowRules`.** Forced, not chosen. `injectAuth()` can only
substitute a secret into one request header of a request the broker makes. Here the broker makes no
request: the token goes into a child process's environment. A `baseUrl` would imply a proxyable call that
does not exist, and `allowRules` would bound a request that is never issued — both would be decorative,
and decorative security controls are worse than none because reviewers trust them.

**D2 — Grouped with the anthropic providers, not the `generic-*` block.** Both branches key on the
`inject_only` flag (`isInjectOnly()`, `:434-437`), never on position, so grouping is purely editorial.
A reader looking up "what anthropic entries exist" must see all three together; the alternative hides a
Claude provider inside a block titled "generic".

**D3 — Extend `$injectOnlyComment` rather than leave it.** It currently reads as a narrative about
OpenConnector's unbounded hosts and says "the five entries". Adding a sixth inject-only entry elsewhere
silently falsifies the reader's inference even though no sentence becomes untrue. The comment now states
the general rule: `inject_only` means *the broker cannot bound this call, so it refuses to make it* —
which covers both an unbounded host and a non-HTTP consumer.

**D4 — Version `1.5.0` → `1.6.0`.** The `$injectOnlyComment` precedent suggests the current version is
`1.4.0`, but that is stale: commit `6473d7e37` took `1.5.0` when it added the anthropic descriptors.
Verified against HEAD rather than inferred from the comment.

**D5 — No PHP, and deliberately no tests.** The inject-only branches are pre-existing, keyed on the flag,
and already covered. Adding a provider-specific test would pin the *provider* into a code path that is
correctly provider-agnostic. Verification is behavioural (Task 3), against the existing implementation.

## Risks / Trade-offs

**R1 — The secret leaves OpenRegister. This is the whole trade and must not read as an oversight.**
An inject-only credential's secret is handed to the calling app, weakening the broker's "the app never
sees the secret" property. It is *forced* (a CLI needs it in its env — there is no seam to hide behind),
*precedented* (the five `generic-*` entries make exactly this trade), and *bounded* (Guard 1 owner/IDOR
and Guard 2 `allowedApps` both run first; Doriath keeps custody; app config holds only a `credentialRef`).
The counterfactual is worse: without it the token sits in app config in cleartext. Mitigation: the entry's
`$comment` says so plainly, and directs readers to the zero-knowledge `anthropic` entry wherever it works.

**R2 — A future provider could reach for `inject_only` to dodge writing `allowRules`.** `inject_only`
is now justifiable by two distinct arguments, which makes it easier to invoke lazily. Mitigation: D3's
comment states the test — the broker must be *unable* to bound the call — and instructs authors to prefer
a host-locked entry.

**R3 — The ToS constraint is declared here but enforced in link 2.** A constraint recorded with
enforcement nowhere is precisely the orphaned-capability defect this fleet has been burned by. It is
called out rather than left implicit. Bounded: until link 2 ships there is no `cli` dispatch and no
`resolveInjectable()` caller, so nothing can use the credential — the gap is inert, not exploitable. If
link 2 is abandoned, this entry should be reverted rather than left as a declared-but-unenforced rule.

**R4 — Landing order is load-bearing.** Hermiq's manifest declares `anthropic-cli`, and
`ProviderCatalogue::get()` is an exact key lookup, so the reverse order gives users a 400 on save. This
change MUST merge first. Recorded in the chain's `depends_on`, not left to reviewer memory.

**R5 — `authScheme` is descriptive only and a reviewer may misread it as a control.** All five `generic-*`
entries carry one despite the broker injecting nothing for them; this entry follows suit for consistency.
The `$comment` states explicitly that the broker injects nothing here.
