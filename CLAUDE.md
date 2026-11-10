# openregister — l10n

App id is **`openregister`**. Every user-visible string is wrapped:

```js
t('openregister', 'Some string')
n('openregister', 'object', 'objects', count)
n('openregister', '{count} object', '{count} objects', count, { count })
```

## Two catalogues, different consumers

| Files | Set | Read by |
| --- | --- | --- |
| `l10n/*.js` | **frontend** | `OC.L10N.register` → `t()` / `n()` |
| `l10n/*.json` | **backend** | PHP `IL10N` |

Not two renderings of one source — separate catalogues, separate consumers. **A `t()` call
in `.vue`/`.js` belongs in `en.js`, never in `en.json`.** Mixing them up is the bug that
once left `en.js` ~700 keys behind while every gate stayed green. There is no scanner for
the backend set; it is maintained by hand.

## Commands

| You want to… | Run |
| --- | --- |
| Check / view / edit a key | `node scripts/l10n-ai.js has\|get\|find\|add\|set\|rm\|rename` |
| Audit `en.js` vs `src/` (missing / unused / unwrapped) | `npm run check:l10n` |
| **CI gate** — does `en.js` cover every `t()`/`n()` call? | `npm run test:l10n` |
| **CI gate** — every locale complete and well-formed? | `npm run test:l10n:parity` |
| Extract new keys into `en.js` | `npm run test:l10n:write` |
| State of one locale | `npm run l10n:status -- <loc>` |
| Write a patch (gated, dry-run by default) | `npm run l10n:apply -- <loc> patch.json [--apply]` |
| Verify one locale before committing | `npm run l10n:selfcheck -- <loc>` |
| See what actually renders | `npm run l10n:runtime -- <loc>` |
| Prove the gates still refuse | `npm run l10n:gatetest -- <loc>` |

Never hand-edit `l10n/*.js` — 37 files that must stay in sync, no validation, and reading
one into context costs hundreds of tokens per call. **`apply.js` is the only writer.**

## Rules

**Every locale is key-for-key identical to `en.js`.** `test:l10n:parity` fails on a missing
key, an empty value or wrong plural arity, for every locale, with no exemption list and no
env override. Add an English string and you translate it or you unwrap it. Never add an
exemption to get a green build.

**An untranslatable string should not be wrapped at all.** An input placeholder or example
value (`sk-...`, `myapp`, `https://example.com/webhook`) gets unwrapped in `src/` *and*
deleted from all 37 bundles including `en.js`, in one commit. Deleting it from the locales
alone leaves `check:l10n` reporting it unused forever.

**A genuine cognate is written out and recorded.** `CSV`, `PDF`, `URL`, `Flows` in nl/de/da:
write the value so the locale keeps parity, and record why in `locales/<loc>.json` under
`"cognates"`. `apply.js` refuses an identical value without a record; parity fails on an
unjustified one *and* on a stale record whose value is no longer identical. Enforcement is
opt-in per locale, keyed on `locales/<loc>.json` existing — the gate prints which locales
are enforced and which are merely unreviewed, so a green run is not evidence of review.

Measure that split, never eyeball it: a key is untranslatable only if **no locale has ever
carried a value differing from it**. `node scripts/l10n-ai.js get <key>` answers it.
`value === key` without a record is the worst option available — absent falls back to
English and stays visibly untranslated, identical is indistinguishable from finished work
and so never gets revisited.

**An `n()` call's key is NEITHER source string.** It is `"_<singular>_::_<plural>_"` —
`pluralIdentifier` in `scripts/l10n/lib.js`. Storing forms under the bare singular renders
for `count === 1` and falls back to English everywhere else; that shipped in all 37 bundles
while passing every gate.

**Plural arrays match that locale's own `nplurals`, and are never copied between
languages.** An array shorter than the index the runtime asks for renders **blank** — the
one defect you cannot see by reading the file. Equal form counts do not mean equal
boundaries. **Read `docs/l10n-workflow.md` §7.1 before writing an array.**

At runtime the form index comes from the library's own `getPlural`, not the file's
`plural=` header: the header governs the arity gate, the library governs which element
renders. `npm run l10n:runtime -- <loc>` is the only check that catches a wrong boundary,
and **nothing** catches a wrong noun form.

**`{plural}` is banned** — in a key, in a value, and inside a translation call, including
the `? 's' : ''` spelling. Four gates enforce it; `l10n:gatetest` proves they refuse.
Use `n()`. Why: `docs/l10n-workflow.md` §7.4.

**A plural form must not also be a catalogue key.** `translatePlural` re-translates the form
it selects, so such a form renders the *other* key's value, invisibly. Parity fails on it.

**Never overwrite a real translation.** `l10n-ai.js` refuses without `--force`, `apply.js`
without `--allow-replace`. Trust the refusal; replace only what is genuinely wrong, and say
why in the commit.

**But a locale pass must grammatically audit the pre-existing values and fix what is bad.**
That rule guards against changes of taste, not against fixing grammar. No gate sees a
wrongly-inflected value: it is not empty, not identical to English, has the right arity, and
reads as finished work. Method in `docs/l10n-workflow.md` §6.9; what past passes found, and
how big to expect it to be, in `docs/l10n-audit-findings.md`.

**Adding a string:** `en` is required (identical to the key is correct — `en` *is* the
source), other locales optional, `--locales=` narrows. A new English string puts every
finished locale one key short, which parity treats as fatal — procedure in
`docs/l10n-workflow.md` §6.15.

**Commit one language at a time**, so a bad locale can be reverted alone.

## Gotchas

- **`clean:l10n` is a dry run on purpose.** It removes keys from all 37 files, and some
  candidates are live UI prose nobody has wrapped yet. Cross-check against
  `find:unwrapped`, then remove by hand, matching the whole quoted literal.
- **Locale files are not linted or formatted, by design.** `serializeJs` emits the exact
  Nextcloud/Transifex layout; `.prettierignore` excludes `l10n/`.
- `l10n-ai.js rename` does not rewrite call sites — grep `src/` afterwards. `set` refuses
  pluralized (array) keys.
- `find:unwrapped` is deliberately high-recall (~1500 candidates). Audit by hand; do not
  tighten the heuristic until real strings are missed.
- **A `SKIP` or `NOTE` from `selfcheck` / `runtime-check` is not a bug to tighten away.**
  See "Two traps in the verification scripts" in `scripts/l10n/README.md` first.
- `scripts/l10n/lib.js` is the **origin** copy; `openconnector` vendors it. Keep them in
  sync — the only intended divergence is `DYNAMIC_KEYS`. openconnector is currently behind:
  old `scripts/lib/l10n.js` path, plurals stored under the bare singular, and a
  `check-l10n-parity.js` predating the arity, identical-value and finished-set gates.

## Known state

`npm run test:l10n` is **red at HEAD, and not because of l10n work**: a `development` merge
replaced the Dutch GDPR source terms with English ones and added flow strings, leaving 17
keys used in `src/` but missing from `en.js`. That is the §6.15 procedure and its own
commit. Everything else is green.

Do not trust key or locale counts written down anywhere — `npm run test:l10n:parity` prints
the current parity / enforced / unreviewed split, and that is the only number that cannot go
stale.

## Further reading

| Doc | What |
| --- | --- |
| `docs/l10n-workflow.md` | The runbook: the pass in order, every gate refusal, the traps catalogue, per-locale plural data (§7.1) |
| `scripts/l10n/README.md` | Tooling layout and what each script refuses |
| `docs/l10n-ui-translation.md` | The non-mechanical parts: register, button conventions, per-locale decisions |
| `docs/l10n-audit-findings.md` | What past audits actually found — defect rates, where to look first |
