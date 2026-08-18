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

Separate catalogues, separate consumers — not two renderings of one source. A
change to one implies nothing about the other. **A `t()` call in `.vue`/`.js`
belongs in `en.js`, never in `en.json`.** There is no scanner for the backend set
(it would have to walk `lib/` for PHP `$l->t()`), so `en.json` is maintained by hand.

Re-measure before trusting any number here — `npm run check:l10n` prints it all.
**As of 2026-08-18**: `en.js` holds 2052 keys, `src/` uses all of them, 0 unused,
100 unwrapped literals outstanding. 25 locales at full parity, 11 in progress.

## Commands

| You want to… | Run |
| --- | --- |
| Check a key / view its values | `node scripts/l10n-ai.js has\|get\|find` |
| Add, update, remove, rename a key | `node scripts/l10n-ai.js add\|set\|rm\|rename` |
| Audit `en.js` vs `src/` (missing / unused / unwrapped) | `npm run check:l10n` |
| Assert `en.js` covers every `t()`/`n()` call (**CI gate**) | `npm run test:l10n` |
| Gate every locale (parity, empty, arity, cognates) (**CI gate**) | `npm run test:l10n:parity` |
| Extract new keys into `en.js` | `npm run test:l10n:write` |
| Find prose in `.vue` that isn't wrapped yet | `npm run find:unwrapped` |
| Delete keys no source file references | `npm run clean:l10n` (dry-run) |

`test:l10n` is the coverage gate; `check:l10n` is the richer developer audit (adds
unused + unwrapped, no write mode). Both read `en.js` through the same extractor.

## Translating one locale

Everything lives in **`scripts/l10n/`**. Read two documents, in this order:

1. **`scripts/l10n/README.md`** — the mechanical pass: which command in which order,
   and what each gate refuses.
2. **`docs/l10n-ui-translation.md`** — what is *not* mechanical: measuring register
   against core rather than assuming it (five consecutive locales measured
   differently, and there are three separate *button* conventions), the plural
   boundaries per language, and the conventions already established.

| You want to… | Run |
| --- | --- |
| See what a locale still needs | `npm run l10n:status -- <loc>` |
| Get the worklist (`absent ∪ unjustified-identical`) | `npm run l10n:worklist -- <loc> > todo.json` |
| Harvest candidates from core + sibling apps | `npm run l10n:harvest -- <loc> [todo.json]` |
| Register-check a patch **before** applying it | `npm run l10n:patchcheck -- <loc> patch.json` |
| Write a patch (6 gates, dry-run by default) | `npm run l10n:apply -- <loc> patch.json [--apply]` |
| Verify a locale before committing | `npm run l10n:selfcheck -- <loc>` |
| See what actually renders | `npm run l10n:runtime -- <loc>` |

`apply.js` is the **only** writer, and refuses a whole patch rather than landing
half of it. Two per-locale files back the workflow, and the gates read both:
`locales/<loc>.json` (measured register, justified cognates, audited corrections)
and `detectors/<loc>.js` (closed word lists plus must-fire / must-not-fire controls).

The two things that cost most when skipped: **read the locale's own existing values
before coining a term** (`lt` translates `webhook` in all 40 of its keys, which is
not what you would guess), and **verify every harvested hit at its call site** —
core ships `Right` as *right-aligned* and `Bucket` as an S3 bucket, and both pass
every automated check.

## Hard rules

**Every finished locale is key-for-key identical to `en.js`, always.** Enforced, not
aspirational: `test:l10n:parity` fails the build when a finished locale is missing a
key. Add an English string and you translate it or you unwrap it — you never drop a
locale from the finished set to get a green build.

That splits into two cases, and getting the split wrong is the easy mistake:

- **Untranslatable / non-prose** — an input placeholder or example value (`sk-...`,
  `myapp`, `https://example.com/webhook`). Do not wrap it in `t()` at all. If it is
  already wrapped, unwrap it in `src/` **and** delete the key from all 37 bundles
  including `en.js`, in one commit. Deleting it from the locales alone leaves
  `check:l10n` reporting it unused forever.
- **Translatable but identical in this language** — a genuine cognate (`CSV`, `PDF`,
  `RBAC`, `URL`, `Avatar` in many languages, `Flows` in nl/de/da). **Write it out**
  so the locale keeps parity, and record why in `locales/<loc>.json` under
  `"cognates"`. That record is enforced: `apply.js` refuses an identical value
  without one, and `test:l10n:parity` fails both an unjustified identical value and
  a *stale* record whose value is no longer identical.

Measure that split, never eyeball it: a key is untranslatable only if **no locale
has ever carried a value differing from it**. Strings that look like pure
punctuation keep failing that test — see README.

Writing `value === key` for anything that is **not** a recorded cognate remains the
worst option available: absent falls back to English and stays visibly untranslated
to tooling, whereas identical renders the same characters while being
indistinguishable from finished work, so nobody revisits it. Audit with
`npm run test:l10n:parity -- --strict-identical`.

**Cognate enforcement is opt-in per locale**, keyed on `locales/<loc>.json`
existing. Only `tr ca et hr lt lv ro sk sl` are held to it; the other 16 predate the rule and
carry ~400 unreviewed identical values, some legitimate. The gate prints which
locales are enforced and which are merely unreviewed, so a green run cannot be read
as verified. **Reviewing those 16 is open work.**

**An `n()` call's catalogue key is NEITHER of its source strings.** It is the
identifier `"_<singular>_::_<plural>_"` — see `pluralIdentifier` in
`scripts/l10n/lib.js`. Storing the forms under the bare singular renders correctly
for count === 1 and falls back to English for every other count, which is what
shipped in all 37 bundles until 2026-08-14 while passing every gate.

**Plural arrays must match that locale's own `nplurals`.** Languages sharing a form
count do not share boundaries — `ru`/`pl`/`cs` are all 3 with mutually incompatible
expressions, and `hr`/`lt` disagree about where forms switch. Never copy an array
between languages. An array SHORTER than the index the runtime asks for renders
blank, and it is the only l10n defect you cannot see by reading the file.

At runtime the form index comes from the library's own per-language `getPlural`,
**not** the file's `plural=` expression: `register(app, bundle)` ignores a plural
function passed to it. The header governs the arity gate; the library governs which
element renders.

**They can disagree on the ORDER, not just the count — and then a file that matches
its own header is wrong at every count.** Latvian's header carries the legacy gettext
order (`[one, other, zero]`) while the library partitions it `[zero, one, other]`, so
`lv` arrays are deliberately ordered by the library and `locales/lv.json` records
`"pluralOrder": "library"`. `runtime-check.mjs` compares the two partitions and fails
without that acknowledgement, so the order cannot be silently "corrected" back. Of
the 25 finished locales only `lv` is affected; `tr`, `rm` and `ga` disagree on form
*count* only, which is harmless because the extra forms are never selected.

**Sharing a form COUNT with a neighbour does not mean sharing its arithmetic.** `sk`
and `cs` bound their three forms *absolutely* — `(n>=2 && n<=4) ? 1 : 2` — while
`hr`, `ru`, `pl` and `lt` bound theirs *modularly* on `n%10` / `n%100`. So 22 selects
form 2 in Slovak ("22 objektov", correct) and form 1 in Croatian. An array copied
between two `nplurals=3` Slavic locales is wrong at every compound number.

**`sl` has four forms because Slovenian has a DUAL**, and the dual is not a spelling
variant: it governs noun case, adjective agreement and **verb number** together, so a
count of 2 needs "Objekta sta bila izbrisana" where the plural needs "Objekti so bili
izbrisani". It is the only four-form locale in the finished set. Form 3 carries zero
as well as 5+, as in `sk`.

**Never overwrite an existing real translation.** `l10n-ai.js` refuses without
`--force`; trust the refusal. Only replace a real value when it is genuinely wrong,
and say why in the commit.

**When adding a string, `en` is required; other locales are optional.** Demanding a
hand-written value for 37 locales per string invites exactly the placeholder-shaped
filler the first rule forbids. Add `en` (identical to the key is correct — `en` *is*
the source), plus any locale you can genuinely do well. Use `--locales=` to narrow.

**Commit one language at a time**, so a bad locale can be reverted alone.

## Gotchas

- **`clean:l10n` needs review before every run.** It removes keys from **all 37**
  locale files, and some candidates are live UI prose nobody has wrapped in `t()`
  yet — deleting those discards translations needed the moment someone wraps the
  string. The npm alias is deliberately the dry run. Cross-check against
  `find:unwrapped`, then remove by hand, matching the **whole quoted literal**.
- Locale files are **not** linted or formatted, by design. `serializeJs` emits the
  exact on-disk Nextcloud/Transifex layout; `.prettierignore` excludes `l10n/`, and
  `eslint --fix` would rewrite all ~4400 lines and diverge from what Transifex
  regenerates.
- `l10n-ai.js rename` does **not** rewrite call sites. Grep `src/` afterwards.
  `l10n-ai.js set` refuses pluralized (array) keys — edit those by hand.
- `find:unwrapped` is deliberately high-recall (~1500 candidates). Audit by hand; do
  not "fix" it by tightening the heuristic until real strings are missed.
- `test:l10n:parity` **passes, and must keep passing.** Empty values and wrong
  plural arity fail for *every* locale, because those render blank. The finished set
  is `FINISHED_DEFAULT` in the script, overridable with `L10N_FINISHED_LOCALES`; add
  a locale the moment it reaches parity.
- **A `SKIP` or `NOTE` from `selfcheck.js` / `runtime-check.mjs` is not a bug to
  tighten away**, and neither is a locale that keeps `{plural}` (`es` in all five
  keys, `ca` in four). Both have bitten; see "Two traps in the verification scripts"
  in `scripts/l10n/README.md` before changing an assertion.
- `scripts/l10n/lib.js` is the **origin** copy; `openconnector` vendors it, since the
  two apps ship separate npm packages. Keep them in sync — the only intended
  divergence is `DYNAMIC_KEYS`. As of 2026-08-14 openconnector is BEHIND on three
  counts: it still has the file at the old `scripts/lib/l10n.js` path, still stores
  plurals under the bare singular, and its `check-l10n-parity.js` predates the arity,
  identical-value and finished-set gates.
