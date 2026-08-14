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

**A `t()` call in `.vue`/`.js` belongs in `en.js`, never in `en.json`.**
`tests/l10n/check-l10n.js` used to assert frontend keys against `en.json` — a file
no frontend code path reads — which demanded bookkeeping in the backend catalogue
while the one the browser loads went unaudited. It targets `en.js` now.

There is **no scanner for the backend set**. Auditing `en.json` would mean walking
`lib/` for PHP `$l->t()` calls, not `src/`. Until that exists it is maintained by hand.

Re-measure before trusting any number below — `npm run check:l10n` prints it all.
**As of 2026-08-14**: `en.js` holds 2058 keys, `src/` uses all of them, 0 unused, 100 unwrapped literals outstanding.

## Commands

| You want to… | Run |
| --- | --- |
| Check a key / view its values | `node scripts/l10n-ai.js has\|get\|find` |
| Add, update, remove, rename a key | `node scripts/l10n-ai.js add\|set\|rm\|rename` |
| Audit `en.js` vs `src/` (missing / unused / unwrapped) | `npm run check:l10n` |
| Gate every locale (missing, identical, plural arity) | `npm run test:l10n:parity` |
| Assert `en.js` covers every `t()`/`n()` call (**the CI gate**) | `npm run test:l10n` |
| Extract new keys into `en.js` | `npm run test:l10n:write` |
| Find prose in `.vue` that isn't wrapped yet | `npm run find:unwrapped` |
| Delete keys no source file references | `npm run clean:l10n` (dry-run) |

`test:l10n` is the gate CI runs; `check:l10n` is the richer developer audit (it
adds unused + unwrapped but has no write mode). Both read `en.js` through the same
extractor, so they always agree on what "used" means.

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

**An `n()` call's catalogue key is NEITHER of its source strings.** It is the
identifier `"_<singular>_::_<plural>_"`, which is the only thing the runtime looks
up — see `pluralIdentifier` in `scripts/lib/l10n.js`, and `translatePlural` in
`@nextcloud/l10n`. Storing the forms under the bare singular renders correctly for
count === 1, because `translate()` takes element 0 of an array, and falls back to
English for every other count. That shape shipped in all 37 bundles until
2026-08-14 and passed every gate while `3 objects` rendered English everywhere.

**Plural arrays must match that locale's own `nplurals`.** The count comes from
the locale file's own header, and the *expression* differs between languages that
share a count — Russian, Polish and Czech are all `nplurals=3` with three
mutually incompatible rules. Never copy a plural array from one language to
another. An array SHORTER than the form index the runtime asks for renders blank,
and it is the only l10n defect you cannot see by reading the file.

At runtime the form index comes from the library's own per-language `getPlural`,
**not** from the file's `plural=` expression: `register(app, bundle)` ignores a
plural function passed to it. So the header governs the arity gate, while the
library governs which element is shown. They agree everywhere today except `ga`
(header 5, library 3), `rm` and `tr` (header 2, library 1) — extra forms that are
simply never selected. No array is short anywhere.

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
  locale files. Its list is empty today (0 unused), so any entry that appears is
  new and worth reading before acting on. Some candidates are live UI prose nobody
  has wrapped in `t()` yet — deleting those discards translations needed the moment
  someone wraps the string. The npm alias is deliberately the dry run, not
  `--apply`. Cross-check against `npm run find:unwrapped`, then remove by hand.
  When checking a key yourself, match the **whole quoted literal**, not a
  substring — `Cleanup completed` occurs inside `Cleanup completed successfully.
  Deleted {count} entries.`, which is not a reference to it.
- **`collectUsedKeys` is deliberately wider than the audit set.** It counts an
  `n()` call's identifier *and* both source strings as used, so `clean-l10n.js`
  cannot delete a live plural key; `scripts/check-l10n.js` counts only the
  identifier, so it still tells a human when a bare singular is dead. Audit informs,
  cleaner destroys — they err in opposite directions on purpose.
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
  against the previous run rather than expecting green. The 16 finished locales sit
  at 1–2 missing keys; the other 20 at ~1001.
- `scripts/lib/l10n.js` is the **origin** copy; `openconnector` carries a vendored
  one, because the two apps ship separate npm packages and there is no import path
  between them. Keep them in sync — the only intended divergence is `DYNAMIC_KEYS`,
  which is app-specific. As of 2026-08-14 openconnector is BEHIND on two counts: it
  still stores plurals under the bare singular, and its `check-l10n-parity.js`
  predates the arity and identical-value gates here.

## Translating a whole locale

Read **`docs/l10n-ui-translation.md`** first. It covers the parts that are not
mechanical: measuring a language's formality register against Nextcloud core
instead of assuming it, why harvested translations from core and sibling apps
must be checked against the call site, and the per-locale conventions already
established for the 12 finished languages.
