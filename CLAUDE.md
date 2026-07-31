# openregister — l10n tooling

The app id is **`openregister`**. Every wrap is:

```js
t('openregister', 'Some user-visible string')
n('openregister', '{count} object', '{count} objects', count, { count })
```

Use the scripts for CRUD on `l10n/*.js`. Hand-editing means 37 files kept in sync
by hand, no validation and no consistent formatting. Reading a whole locale file
into context also costs hundreds of tokens per call, and the conversation is
resent every turn.

## Two independent translation sets

| File set | Consumer | Read by |
| --- | --- | --- |
| `l10n/*.js` | **frontend** | `OC.L10N.register` → `t()` / `n()` |
| `l10n/*.json` | **backend** | PHP `IL10N` |

They are **separate catalogues with separate consumers**, not two renderings of
one source. Both are real and both are maintained. Do not assume a change to one
implies the other.

Current state worth knowing: `en.js` holds 1410 keys while `src/` uses ~2011, so
the frontend English catalogue is ~600 keys behind the code. `npm run
test:l10n:write` extracts into **`en.json` only** — nothing maintains `en.js`.
`npm run check:l10n` is the only thing that audits it.

## Commands

| You want to… | Run |
| --- | --- |
| Check a key / view its values | `node scripts/l10n-ai.js has\|get\|find` |
| Add, update, remove, rename a key | `node scripts/l10n-ai.js add\|set\|rm\|rename` |
| Audit `en.js` vs `src/` (missing / unused / unwrapped) | `npm run check:l10n` |
| Gate every locale (missing, identical, plural arity) | `npm run test:l10n:parity` |
| Assert `en.json` covers every `t()`/`n()` call | `npm run test:l10n` |
| Extract new keys into `en.json` | `npm run test:l10n:write` |
| Find prose in `.vue` that isn't wrapped yet | `npm run find:unwrapped` |
| Delete keys no source file references | `node scripts/clean-l10n.js` (dry-run) |

## Hard rules

**Never write a value equal to its key.** This is the one rule that matters most,
because breaking it is invisible.

- **Absent** → `OC.L10N` falls back to the English source. Renders correct text,
  and every tool can still see the key is untranslated, so it stays on the list.
- **`value === key`** → renders the same characters but is indistinguishable from
  finished work, to tooling and to the next maintainer. It is never revisited.

So a legitimate cognate (`ID`, `URL`, `CSV`, `PDF`, `RBAC`, `Webhook`, and
`Avatar` / `Format` / `Metadata` in many languages) is **omitted**, not written
out. `npm run test:l10n:parity` fails on identical values for this reason; it
reports absent ones separately and less severely.

**Plural arrays must match that locale's own `nplurals`.** The count comes from
the locale file's own header, and the *expression* differs between languages that
share a count — Russian, Polish and Czech are all `nplurals=3` with three
mutually incompatible rules. Never copy a plural array from one language to
another. A wrong-length array makes the string render blank at runtime, and it is
the only l10n defect you cannot see by reading the file.

**Never overwrite an existing real translation.** `l10n-ai.js` refuses without
`--force`; trust the refusal and investigate. Only replace a real value when it
is genuinely wrong, and say why in the commit.

**When adding a string, `en` is required; other locales are optional.** With 37
shipped locales, demanding a hand-written value for each one per string is not
workable and invites exactly the placeholder-shaped filler the first rule
forbids. Add `en` (value identical to the key — that is correct here, `en` *is*
the source), plus any locale you can genuinely do well, and leave the rest
absent. Use `--locales=` to narrow.

**Commit one language at a time** when translating, so a bad locale can be
reverted alone.

## Gotchas

- **`clean:l10n` needs review before every run.** It removes keys from **all 37**
  locale files. Of its current 405-key list, 8 occur in `src/` as a complete
  quoted literal, and **2 of those are live UI prose that was simply never
  wrapped** (`Copy`, `Creating...`). Deleting those discards translations that are
  needed the moment someone wraps the string. The other 6 are safe: `Cleanup
  completed` and `Forbidden` appear only in `.spec.js` mocks, `3`/`30` are numeric
  webhook defaults, and two are placeholder example URLs.
  Dry-run (`node scripts/clean-l10n.js`), cross-check against
  `npm run find:unwrapped`, then remove by hand.
  When checking a key yourself, match the **whole quoted literal**, not a
  substring — `Cleanup completed` occurs inside `Cleanup completed successfully.
  Deleted {count} entries.`, which is not a reference to it.
- Locale files are **not** linted, by design. `serializeJs` emits the exact
  on-disk Nextcloud/Transifex layout; `eslint --fix` would rewrite all ~4400
  lines to tabs and single quotes and diverge from what Transifex regenerates.
  The eslint rules are for source code, not generated translation data.
- `l10n-ai.js rename` does **not** rewrite call sites. Grep `src/` afterwards.
- `l10n-ai.js set` refuses pluralized (array) keys — edit those by hand.
- `find:unwrapped` is deliberately high-recall (~1500 candidates). Expect false
  positives and audit by hand; do not "fix" it by tightening the heuristic until
  real strings are missed.
- `test:l10n:parity` currently fails: most locales are incomplete. Compare
  against the previous run rather than expecting green.

## Translating a whole locale

Read **`docs/l10n-ui-translation.md`** first. It covers the parts that are not
mechanical: measuring a language's formality register against Nextcloud core
instead of assuming it, why harvested translations from core and sibling apps
must be checked against the call site, and the per-locale conventions already
established for the 12 finished languages.
