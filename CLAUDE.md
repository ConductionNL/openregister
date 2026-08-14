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
**As of 2026-08-14**: `en.js` holds 2051 keys, `src/` uses all of them, 0 unused, 100 unwrapped literals outstanding. 19 locales at full parity (2051 keys each), 17 in progress.

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

**Every finished locale is key-for-key identical to `en.js`, always.** All 19
carry the same key set, so their files have the same line count. This is
enforced, not aspirational: `npm run test:l10n:parity` fails the build when a
locale in the finished set is missing a key, and it runs in CI as its own leg.
Add an English string and you translate it or you unwrap it — you do not leave 19
locales one key short, and you do not drop a locale from the finished set to get
a green build.

That splits into two cases, and getting the split wrong is the easy mistake:

- **Untranslatable / non-prose** — an input placeholder or example value
  (`sk-...`, `myapp`, `https://example.com/webhook`). Do not wrap it in `t()` at
  all. If it is already wrapped, unwrap it in `src/` **and** delete the key from
  all 37 bundles including `en.js`, in one commit. Deleting it from the locales
  alone leaves `check:l10n` reporting it unused forever.
- **Translatable but identical in this language** — a genuine cognate (`CSV`,
  `PDF`, `RBAC`, `URL`, `Avatar` in many languages, `Flows` in nl/de/da). **Write
  it out**, so the locale keeps parity, and record why per key.

Measure that split, never eyeball it: a key is untranslatable only if **no locale
has ever carried a value differing from it**. Two strings that look like pure
punctuation fail exactly that test, and deleting them would have destroyed real
translations — `UUID:` (fr carries `"UUID :"`; French spaces before a colon) and
`{property} - {other}` (ru carries an em dash).

Writing `value === key` for anything that is **not** a recorded cognate remains
the worst option available: absent falls back to English and stays visibly
untranslated to tooling, whereas identical renders the same characters while
being indistinguishable from finished work, so nobody ever revisits it. Audit a
locale for that with `npm run test:l10n:parity -- --strict-identical`, which
flags every identical value, legitimate cognates included.

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
- `test:l10n:parity` **passes, and must keep passing.** It gates the 19 finished
  locales at key-for-key parity and reports the 17 in-progress ones as a backlog
  (~998 keys each) without failing. Empty values and wrong plural arity fail for
  *every* locale, finished or not, because those render blank at runtime. The
  finished set lives in `FINISHED_DEFAULT` in the script and is overridable with
  `L10N_FINISHED_LOCALES`; add a locale to it the moment it reaches parity.
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
