# openregister — l10n tooling

When working on l10n, follow the @docs/l10n-workflow.md workflow.

---

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
**As of 2026-08-20**: `en.js` holds 2052 keys, 34 locales at full parity, 2 in progress
(`be bs`, in that order — the owner has confirmed the order, so no need to
re-ask per locale). **`test:l10n` is currently RED at HEAD and not because of l10n work**:
a `development` merge replaced the Dutch GDPR source terms with English ones and added
flow strings, leaving 17 keys used in `src/` but missing from `en.js`. That is the
`docs/l10n-workflow.md` §6.15 procedure and its own commit — see §10.

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

Everything lives in **`scripts/l10n/`**. Read three documents, in this order:

1. **`docs/l10n-workflow.md`** — the runbook, and the only one you strictly need:
   the twelve-step pass in order, every gate refusal and what it means, the traps
   catalogue, and the state of the remaining locales. Start here every time.
2. **`scripts/l10n/README.md`** — the tooling layout and what each script refuses.
3. **`docs/l10n-ui-translation.md`** — what is *not* mechanical: measuring register
   against core rather than assuming it, and reading each verdict for what it actually
   is. Three consecutive locales came out "informal" for three *different* reasons —
   Irish has **no T-V distinction at all**, so the label names the only address form;
   Maltese **has** one and merely leaves it unused; Icelandic **had** one and abandoned
   it in the 20th century, so its V-forms are archaic rather than absent or merely
   unfashionable. There are also five separate *button* conventions, one of which is graded
   by string length rather than categorical and has now been measured in two locales. Plus the plural
   boundaries per language and the conventions already established. **Locales measuring
   the same way is not evidence they are the same case.**

| You want to… | Run |
| --- | --- |
| See what a locale still needs | `npm run l10n:status -- <loc>` |
| Get the worklist (`absent ∪ unjustified-identical`) | `npm run l10n:worklist -- <loc> > todo.json` |
| Harvest candidates from core + sibling apps | `npm run l10n:harvest -- <loc> [todo.json]` |
| Register-check a patch **before** applying it | `npm run l10n:patchcheck -- <loc> patch.json` |
| Write a patch (6 gates, dry-run by default) | `npm run l10n:apply -- <loc> patch.json [--apply]` |
| Verify a locale before committing | `npm run l10n:selfcheck -- <loc>` |
| See what actually renders | `npm run l10n:runtime -- <loc>` |
| Sweep a non-Latin locale for script coverage | `npm run l10n:script -- <loc>` |
| Prove the parity gate holds a locale | `npm run l10n:gatetest -- <loc>` |

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
has ever carried a value differing from it** — `node scripts/l10n-ai.js get <key>`
answers that in one command. Strings that look like pure punctuation or pure branding
keep failing the test (`UUID:` has a value in `fr`; core `lt` translates `Slug`).

Writing `value === key` for anything that is **not** a recorded cognate remains the
worst option available: absent falls back to English and stays visibly untranslated
to tooling, whereas identical renders the same characters while being
indistinguishable from finished work, so nobody revisits it. Audit with
`npm run test:l10n:parity -- --strict-identical`.

**Cognate enforcement is opt-in per locale**, keyed on `locales/<loc>.json`
existing. Only `tr ca et hr lt lv ro sk sl bg sr rm ga mt is cs lb sq mk` are held to it; the other 15 predate the rule and
carry ~375 unreviewed identical values, some legitimate. The gate prints which
locales are enforced and which are merely unreviewed, so a green run cannot be read
as verified. **Reviewing those 16 is open work.**

**An `n()` call's catalogue key is NEITHER of its source strings.** It is the
identifier `"_<singular>_::_<plural>_"` — see `pluralIdentifier` in
`scripts/l10n/lib.js`. Storing the forms under the bare singular renders correctly
for count === 1 and falls back to English for every other count, which is what
shipped in all 37 bundles until 2026-08-14 while passing every gate.

**Plural arrays must match that locale's own `nplurals`, and never be copied between
languages.** An array shorter than the index the runtime asks for renders **blank**, and
it is the only l10n defect you cannot see by reading the file. The separate ways this
goes wrong — equal form counts with different boundaries, modular vs absolute
arithmetic, the header and the library disagreeing on ORDER, the header and the library
putting the BOUNDARIES in different places so that no reordering reconciles them
(Icelandic and Macedonian, in opposite directions), Slovenian's dual, a
counting form chosen by the sentence rather than the form index (Bulgarian), and a
library that collapses every count onto form 0 in a language that *does* pluralise
(Romansh, where that makes form 0 wrong at every count but 1) — are
worked through with the per-locale table in **`docs/l10n-workflow.md` §7.1**. Read it
before writing an array. `npm run l10n:runtime -- <loc>` is the only check that catches
a wrong boundary, and **nothing** catches the wrong noun form.

At runtime the form index comes from the library's own per-language `getPlural`,
**not** the file's `plural=` expression: `register(app, bundle)` ignores a plural
function passed to it. The header governs the arity gate; the library governs which
element renders.

**Never overwrite an existing real translation.** `l10n-ai.js` refuses without
`--force` and `apply.js` without `--allow-replace`; trust the refusal. Only replace a
real value when it is genuinely wrong, and say why in the commit.

**But a locale pass MUST grammatically audit the whole pre-existing half and fix what is
bad.** That rule guards against changes of *taste*, not against fixing grammar — do not cite
it to skip the audit. On `is`, 235 of 1052 pre-existing values were defective (wrong gender,
wrong case, malformed compounds, garbled words, foreign stems, wrong senses) and **no gate can
see any of it**. Mechanical checks found 4 of them; the rest needed reading every value. See
`docs/l10n-workflow.md` §6.9 for the method and for the two ways such checks mislead you.

**The defect rate tracks how healthy the locale is upstream, not how long it went un-audited.**
`cs` — one of the sixteen locales that predate all of this tooling, so audited across all 2052
values rather than a pre-existing half — came in at **113 defects (5.5%)** against `is`'s 22%,
with zero garbled words, zero foreign stems, zero agreement failures and zero wrong plural
arrays. Its defects were almost entirely terminology drift and internal inconsistency, and
**counting competing renderings per English term found about 70 of the 113** with no knowledge
of Czech required. Do that count first on any pass. `sk`, audited next because it is `cs`'s
closest sibling, came in lower still at **57 (2.8%)** with zero agreement failures, zero case
errors and zero bad plural arrays — and **37 of its 57 were a single term**, so once the count
finds the one term that drifted, most of the pass is one sweep.

**A `corrections` count of 0 means "unverified", not "clean".** `sk` had a measured register, a
detector, a reviewed cognate set and an empty `corrections`, and the audit still found 57 real
defects — including two semantic reversals invisible to every gate. But a 0 does not imply a
quarter of the file is waiting either; read it as "nobody looked".

**`ca` is the highest of the three healthy locales at 128 defects (6.2%)**, and it is where the
defects stopped being one dominant term: the biggest class was 35 keys, and eleven other classes
carried between two and eleven each. Two of those were **collisions visible on a single screen** —
`Settings`/`Configuration` both rendering `Configuració` next to each other as sibling tab labels in
three dialogs, and `Logs`/`Registers` both rendering `Registres` as the two tabs of one tab bar. That
is the class to hunt first in any locale whose language collapses two of the app's nouns: grep the
`tabs:` arrays and the paired empty states, because a byte-identical collision the *user* can see is
a defect no amount of "both words are correct Catalan" excuses. **The reports also found their own
blind spot** — `l10n:spell` was splitting every Catalan `l·l` word in half and reporting the halves
as misspellings, so a locale's orthography can defeat the tooling silently.

**`lb` generalises as a QUESTION to ask, not as a promise that a sandhi rule is waiting.**
`mk` is where it was asked and came out no. Macedonian obligatorily doubles a definite direct
object with an accusative clitic (`Избриши ГО објектот`), which looks exactly like the Eifeler
Regel and is not the same shape: the trigger is **semantic** (definiteness) rather than
orthographic, and the clitic's position depends on clause type. The check scored **0 of 5** —
ordinary words ending in the letters that spell the definite articles, plus a clause whose
clitic sat in front of the verb where the check was not looking. What paid instead was
**capitalisation**: 118 occurrences over 105 values, the whole dominant class of the pass. So
ask the question every time; expect the answer to be a different rule each time.

**Ask whether the language has an OBLIGATORY SANDHI rule before budgeting the audit.** `lb`
is the counter-example to "budget for terminology, not grammar": its terminology was healthy
and **60 of its 77 corrections were one orthographic rule** — the Eifeler Regel, where
word-final `-n` deletes before any consonant but `n d t z h`. Obligatory, fires several times
per sentence, invisible to every gate, and broken in *both* directions. It is also the **one
mechanical morphology check that has ever paid off here** (the general rule against them still
holds — 4 of 239 on `is`, ~0 of 113 on `cs`), because the trigger is deterministic rather than
agreement-based. It found 44 further violations in the half written during the pass, so split
at HEAD and re-run it on your own work. Method: `docs/l10n-workflow.md` §8.11. The same shape
applies to French elision/liaison, Irish initial mutation and Italian `lo`/`il`.

**And when measuring a bundle's own practice, aggregate by WORD CLASS, not only per lemma.** A
per-lemma consistency check is necessary but not sufficient, and on `lb` believing otherwise
cost a whole round: a lemma occurring in only one environment carries no information, so
`Lueden` (always with `-n`) and `Deele` (always without) both read as "consistent" and 26
values were excused on a "16:0, no counter-example" count that was really one copy-pasted
phrase. By word class the family does both, 118 to 108. **A uniform lemma is evidence of one
decision, not of a rule.**

**Thin core coverage is more dangerous than none.** `rm` and `mt` had zero catalogues, so the
scan THREW and §5 step 2 could not run by accident. `lb` had one catalogue with 72 values, so
it *succeeded* — and would have reported a register verdict computed from **0 markers**, since
those 72 values contain no address form. Measure the marker count, not the catalogue count.
`bs` (55 values) is the same trap.

**A capitalisation ratio conflates two populations, and the confound invents work.** Measure
mid-sentence casing over **prose only** (English key ≥6 words and not Title Case) and
separately ask whether short labels **mirror** the key's Title Case. On `sq` the naive scan
reported the bundle capitalising domain terms 25–35% against a family rate near zero — a
tidy ~110-value defect class that does not exist: prose is 0-of-177, and every hit was a
Title-Cased heading correctly following its source. If a defect class is large, uniform and
concentrated in short values, you are measuring the source, not the translation.

**But the conditioned measurement can still come back real, and on `mk` it did.** Restricted
to prose keys the bundle capitalised **41 against 146** where the sibling frontends run 1:273
and core 2:~480, and the same rule was broken again in its headings (65:507 against core's
7:122). What tells that apart from `sq`'s phantom is that it does not evaporate under the
restriction and that the bundle is **internally inconsistent**: `објект`, the app's most
central noun, is 0:26 lowercase while `Датотека` is 20:0 capitalised. Three of the four
capitalised terms are themselves split. So there was no convention to follow — unlike `sr`,
which capitalised its six first-class concepts one-sidedly across the whole bundle. The `lb`
lesson decides it: **a uniform lemma is one decision copied, not a rule** — by word class the
same bundle does both, 41 to 146.

**Three ways a casing measurement misleads, all met on `mk`.** A stem alternation written
without the `i` flag matches only the lowercase form, so every capitalised occurrence — the
entire thing being measured — is invisible and every term reports a clean 0-up. `(?<=.)`
excludes the value's first word but **not a later sentence's**, so `… пребарување. Објектите
се …` scores as a mid-sentence capital; three first-cut hits were that, and each would have
been "corrected" into an error. And an opening parenthesis, a leading emoji and deliberate
all-caps each license a capital the same way a sentence start does — 14 of `mk`'s 132 raw hits
were those. Review the word list before applying any casing fix.

**An ACRONYM can be a homograph of a register marker, and case is the only thing separating
them.** `ВИ` is Macedonian for *AI* (вештачка интелигенција) and this bundle uses it —
`ВИ-агент`, `ВИ-функции` — while `ви` is the formal dative clitic. A detector that folds case
scores the app's AI vocabulary as deferential address. `detectors/mk.js` therefore **consumes
the all-caps form in `fold()` before lowercasing**: the acronym is always all-caps, the
pronoun is `ви` or sentence-initial `Ви`. That is the `lb` `dir`/`Dir` situation from the other
end — `lb` had to preserve case throughout, `mk` needs it for one token and can spend it up
front, which keeps the word lists readable. Note the hyphen goes in the **left** guard only:
Macedonian attaches acronyms to the following noun with a hyphen, so the acronym must still
match with a hyphen after it.

**`sq`'s LENGTH-GRADED button convention replicated on `mk`, which is what makes it a shape
worth checking rather than one locale's quirk** — and the crossover landed in the same place,
~40 characters. Core `mk` runs 128:1 for the 2sg imperative at ≤14 characters and 6:12 the
other way at 80+. Its `Select`/`Choose`/`Enter` prompts take the 2pl at any length, exactly as
`sq`'s do. But measuring one more prompt family shows the override is **lexically bounded, not
"any prompt"**: `Search` goes the other way and is not close (core 61:1 for 2sg), because a
dropdown you pick from and a field you type into address the user while a toolbar button is
something you press.

**A functional field described in prose is not a functional field.** The `mk` pass wrote the
whole `pluralBoundary` rationale into `pluralNote` and never set `pluralBoundary` itself. This
is the third instance of the `scripts/l10n/locales/<loc>.json` field class after `pluralOrder`
and `spellAllow` — but the first that failed **loudly**, because `runtime-check` treats an
unacknowledged boundary disagreement as fatal. The two earlier ones failed in the safe
direction and so went unnoticed for passes. Set the field, then re-run the check that reads it.

**A marker guard must know where the target puts a MORPHEME boundary, not just what is a
letter.** `(?<!\p{L})` is wrong for Albanian, which attaches the definite ending to acronyms
after a hyphen (`UUID-je`, `Token-i`, `PHP-ja`) — so it matched an inflectional ending as the
2sg copula. `detectors/sq.js` uses `(?<![\p{L}-])`, but must NOT guard the apostrophe, since
`t'ju` is formal and `s'ke` informal. Catalan needed the interpunct *inside* the token class;
same question, opposite answer.

**Check core AND the call site before "fixing" an outlier.** Core `cs` overturned four
candidate corrections, one of which was the only value in its family that actually matched
core. On `sk` the two together overturned **ten** — more than any single class the pass
corrected. Core `sk` itself collapses `Refresh`/`Restore` onto `Obnoviť` and ships
`Prvé`/`Posledné`/`Predchádzajúce` and bare `Hľadať` verbatim; and `Riešiteľ` for *Handler* is
right because the field holds a **person**. See `docs/l10n-workflow.md` §6.9 for the full list.

**When adding a string, `en` is required; other locales are optional.** Add `en`
(identical to the key is correct — `en` *is* the source), plus any locale you can
genuinely do well; `--locales=` narrows. But note a new English string puts every
finished locale one key short, which the parity gate treats as fatal — the procedure is
`docs/l10n-workflow.md` §6.15.

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
