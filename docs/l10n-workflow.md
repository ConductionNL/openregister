# The frontend l10n workflow — complete runbook

Everything needed to take one `l10n/<loc>.js` from partial to complete, correctly, and to
hand the work to someone else mid-stream. This is the **operational** document: order of
operations, which command to run, and what to do when a gate refuses. Two companions:

| Document | Covers | When to read it |
| --- | --- | --- |
| **this file** | the whole pass, in order; every refusal and what it means; the traps catalogue | start here, every time |
| `scripts/l10n/README.md` | the tooling layout, and what each script refuses | when a script surprises you |
| `docs/l10n-ui-translation.md` | the per-locale **linguistic** reference — register verdicts with their evidence, button conventions, plural boundaries, homograph traps per language | before translating a specific locale |

**Keep this file operational.** A pass's durable output is the *rule* it learned, not the
retelling of how it learned it — the worked examples belong in the commit message, in
`locales/<loc>.json`, or in the companion's per-locale section. This document was allowed to
grow to 2284 lines by appending a case study per pass. **Ceiling: 1600 lines.** If a pass
needs room here, it has to earn it by deleting something, not by appending.

`CLAUDE.md` holds the short version of the hard rules, plus a counts snapshot under an
explicit "re-measure before trusting any number here" — treat it that way, and see §2.

---

## 1. Goal and scope

Give the Nextcloud app **openregister** real, correct **frontend** translations in all 36
non-English locales. The repo is its own git checkout at
`<workspace>/apps-custom/openregister`, working branch **`feature/l10n-fixes`**.

**Scope is `l10n/*.js` only** — the frontend catalogue, read by `OC.L10N.register` →
`t('openregister', …)` / `n(…)`. Every string reached that way needs a genuine translation.

**Out of scope:** `l10n/*.json` is the **backend** catalogue with a different consumer
(PHP `IL10N`) and no scanner. A change to one implies nothing about the other. Never harvest
`.json` into `.js` — 31% of shared keys disagree, and some `.json` files carry the wrong
language outright (§8.6).

Also out of scope: the translatable-object-property machinery in `docs/i18n.md`. Different
system, same word.

---

## 2. Reading the current state, and the invariants

**Do not write counts into documentation.** They go stale silently, and this project has
already been bitten: seven consecutive passes bumped `CLAUDE.md` and left
`docs/l10n-ui-translation.md` claiming twelve finished locales while the gate enforced
nineteen. Measure instead — every number you need is one command away.

### 2.1 How to check the counts

**One command gives the whole picture.** `npm run test:l10n:parity` prints, in order:

```
36 required locales; checked 2 translation set(s)
N locale(s) declared finished and held at key-for-key parity:  <the finished set>
M of those also hold every English-identical value to a recorded justification: <enforced>
K finished locale(s) predate the cognate rule and are NOT yet held to it: <unreviewed>
L locale(s) still in progress (not gated):
  · frontend (.js) <loc>: 999 of 2052 key(s) to go     <- the backlog, with counts
OK — every finished locale is at full parity
```

Read all five lines, not just the last. **A green run does not mean everything is
translated** — the backlog prints on passing runs precisely so it cannot be read that way,
and the "NOT yet held to it" line names the locales whose identical values nobody has
checked.

| To find out… | Run |
| --- | --- |
| the whole state: finished / enforced / unreviewed / backlog | `npm run test:l10n:parity` |
| one locale in detail — absent, identical, arity, empty, register | `npm run l10n:status -- <loc>` |
| how `en.js` compares to `src/` — missing, unused, unwrapped | `npm run check:l10n` |
| which locales are **enforced** for cognates | `ls scripts/l10n/locales/` |
| which locales have a register **detector** | `ls scripts/l10n/detectors/` |
| which locales the gate treats as **finished** | `FINISHED_DEFAULT` in `tests/l10n/check-l10n-parity.js` |
| every locale's size at a glance | `wc -l l10n/*.js` |

The last one is the fastest smell test there is — see invariant 2.

### 2.2 The invariants

These are the rules the counts have to satisfy. Each is enforced by a gate; knowing them
is how you tell "in progress" from "broken".

1. **Every finished locale has exactly the same key count as `en.js`.** Not approximately.
   `test:l10n:parity` fails a finished locale that is one key short. This is the parity
   guarantee, and §3.1 is why you never work around it.

2. **Therefore every finished locale has the same *line* count as `en.js`, exactly.**
   `serializeJs` emits one line per key plus a fixed wrapper, so `wc -l l10n/*.js` is a
   one-second check on the whole set: the finished locales are all identical, anything in
   progress is visibly shorter, and a finished locale whose line count differs means a
   duplicated key or a hand edit even when the key count matches.

3. **`absent === 0` and `extra === 0`** for a finished locale. Extra keys matter as much as
   missing ones: a key no longer in `en.js` is dead weight that `check:l10n` reports as
   unused forever.

4. **Empty values and wrong plural arity are fatal for *every* locale**, finished or not,
   because both render **blank** to the user. This is the one class of defect you cannot
   see by reading the file.

5. **Plural array length must equal that locale's own `nplurals`**, taken from its own
   header — not the neighbour's, not the previous locale's. §7.1.

6. **`value === key` is correct in `en.js` and nowhere else.** `en` *is* the source. In any
   other locale it is either a recorded cognate or a defect (§3.2).

7. **For the enforced locales, every identical value carries a written justification** —
   and no justification is left behind for a value that is no longer identical. Both
   directions are checked.

### 2.3 Order of work

**All 36 locales are at full parity. There is no translation queue left**, and the only
remaining streams are the re-audit of the already-finished bundles (§9.2 and
`handoff_re-auditing.txt`) and the source-side defects of §10.

**So the CLOSING TASK of §9.1 is now DUE, and it is not optional cleanup.** With nothing in
progress, `FINISHED_DEFAULT`, the `FINISHED` set and the `L10N_FINISHED_LOCALES` override are
exactly the knobs someone reaches for to turn a red build green. Do that before starting a
re-audit pass.

**Check whether core can decide the register at all before planning a pass**, because §5
step 2 assumes it can. **Measure the marker COUNT, not the catalogue count.** Zero
catalogues is the safe failure — `coreCatalogues()` throws, so step 2 cannot run by
accident. A *thin* core is the dangerous one, and both shapes have now been met: `lb` had one
catalogue with 72 values and `bs` one with 55, so the scan **succeeded** in each case and
would have reported a verdict computed from **0 markers of either polarity**. That is the
shape that lets a pass record a measurement it never made, and it stays relevant to the
re-audit stream: a Tier 1 locale needs its register measured for the first time, so the same
question applies before trusting core for it.

---

## 3. Hard rules — all binding

### 3.1 Every finished locale is key-for-key identical to `en.js`, always

Enforced, not aspirational: `npm run test:l10n:parity` fails the build when a locale in the
finished set is missing a key, and it runs in CI as its own leg. Add an English string and
you **translate it or unwrap it**. You never leave the finished locales one key short, and
you **never drop a locale from the finished set to get a green build** — that is the one
move the gate exists to prevent.

### 3.2 The cognate rule

Two cases, and getting the split wrong is the easy mistake:

- **Untranslatable / non-prose** — an input placeholder or example value (`sk-…`, `myapp`,
  `https://example.com/webhook`). Do not wrap it in `t()` at all. If it is already wrapped,
  unwrap it in `src/` **and** delete the key from all 37 bundles including `en.js`, in one
  commit. Deleting it from the locales alone leaves `check:l10n` reporting it unused forever.
- **Translatable but identical in this language** — a genuine cognate (`CSV`, `PDF`, `RBAC`,
  `Register` in Slovak). **Write it out** so the locale keeps parity, and record a written
  reason (≥15 chars) per key in `scripts/l10n/locales/<loc>.json` under `"cognates"`.

Writing `value === key` for anything that is **not** a recorded cognate is the worst option
available. Absent falls back to English and stays *visibly* untranslated to tooling;
identical renders the same characters while being indistinguishable from finished work, so
nobody ever revisits it.

**`cognates` is a permission list for `value === key` and nothing else.** Do not park notes
about *rejected* cognates there — a future reader will read the entry as permission to leave
the key untranslated, and the gate fails a record whose value is not actually identical. Put
those notes in a free-form field (`lexiconNote`) or the commit message.

### 3.3 Measure the untranslatable/cognate split — never eyeball it

A key is untranslatable only if **no locale has ever carried a value differing from it**,
and that is a one-command test rather than a judgement:

```bash
node scripts/l10n-ai.js get "UUID:" | awk -F'\t' '{print $2}' | sort -u
#   UUID :      <- fr, French spacing before a colon
#   UUID:
```

More than one distinct value means it is **translatable**, so it cannot be unwrapped — it
is at most a cognate. Strings that look like pure punctuation or pure branding keep failing
this test: `UUID:` (above), `{property} - {other}` (`ru` em dash, `hr` en dash), `Slug`
(core `lt` translates it, `sl` renders it `Oznaka`), `Ollama URL` (29 locales translate it).

The reverse also holds: a single distinct value across all 37, for a string that is a bare
product name, is evidence it was never translatable prose to begin with (§6.15).

### 3.4 Follow Nextcloud core per language for register — measure it, never assume

Every locale so far has come out differently. Carrying an answer over from the previous
locale passes every automated check while being wrong in every string that addresses the
user. §5 step 2 is how you measure it; §7.2 records the verdicts.

**A verdict is a label over a reason, and the reasons differ — never copy one without its
reason.** `informal` has meant *the language has no T-V distinction at all* (`ga`), *it has
one and does not use it* (`mt`), and *it had one and abandoned it* (`is`). Those differ in
what a slip would look like, which is what the detector has to gate: where the V-forms are
merely archaic, the likelier error is not the archaic form but the plain modern plural, and
the detector must catch both. §7.2 has the taxonomy.

### 3.5 Read the locale's own existing values before coining any term

Where the file's own pre-existing values and core disagree on **lexicon** (not register), the
file usually wins — splitting the app's own terminology is worse than diverging from core.
The cautionary cases: `lt` translates `webhook` in all 40 of its keys (it looked like an
obvious keep-the-English); the `lv` pass coined `šķautne` for *facet* and `AI aģentus` when
the bundle already had `fasete` and `MI aģents`, and both had to be reverted.

Do this read **before the first batch**, not after the last.

### 3.6 Use the committed tooling in `scripts/l10n/`. Do not write your own

Every script a pass needs is in the repo. It lived in a scratchpad for five passes and got
rebuilt from a handoff each time, which is how it kept re-acquiring the same bugs — one
silently harvested the *previous* language for a whole round. **If you catch yourself
writing a script to load, diff, patch or verify `l10n/*.js`, it already exists.** Extend the
committed one so the next pass inherits the fix.

`apply.js` is the **only** writer. Never hand-edit a bundle.

Two narrow exceptions, both fine: generating the **patch JSON** you feed to `l10n:apply`
(that is data, and it never lands in the repo), and a throwaway scratchpad script for a
one-off negative test that *calls the committed library* rather than reimplementing it.

### 3.7 When adding a NEW English string, `en` is required and the rest are optional

This is the rule for ordinary development, not for a locale pass. Demanding a hand-written
value in 37 locales for every new string invites exactly the placeholder-shaped filler
§3.2 forbids. So: add `en` — where `value === key` is correct, because `en` **is** the
source — plus any locale you can genuinely do well, narrowing with `--locales=`.

The catch, and it is the one that breaks CI: **a new English string puts every finished
locale one key short**, which the parity gate treats as fatal. See §6.15 for the procedure.

### 3.8 Never overwrite an existing real translation

`l10n-ai.js` refuses without `--force` and `apply.js` refuses without `--allow-replace`.
**Trust the refusal.** Only replace a real value when it is genuinely wrong, and say why —
in `corrections` for a pre-existing value (§6.3), in the commit message otherwise. Someone
translated that string; the burden of proof is on the replacement.

**This guards against changes of taste, not against fixing grammar.** Do not cite it to skip
the §6.9 audit.

### 3.9 A `t()` call belongs in `en.js`, never in `en.json`

The two sets have different consumers (§1). There is **no scanner for the backend set** —
it would have to walk `lib/` for PHP `$l->t()` — so `en.json` is maintained by hand, and
nothing will tell you if you put a frontend string there. It simply never renders.

### 3.10 Process rules

- **One commit per language.** So a bad locale can be reverted alone. Tooling fixes
  discovered during a pass go in their **own** commit, before the locale.
- **No approval needed for translation batches.** The owner explicitly declined to review:
  "reviewing thousands of lines of translations is too much for me to begin with." Do not
  ask for sign-off on translation content; do ask when a *convention* decision is the
  owner's to make (§6.4).
- **No `Co-Authored-By` trailers.**
- **No sed/awk/Python on repo code files** — use Edit/Write. Running the repo's own
  `prettier --write` is sanctioned; it is the fix for the `format` gate.
- **Never browser-test.** The owner verifies manually. Never run Playwright or any browser
  automation.
- **Verify before claiming.** Read the code path that consumes a value before calling it a
  bug. When the owner pushes back, re-derive rather than just agree.
- **Never `git checkout --` a bundle mid-pass.** See §6.10 — this destroyed a whole pass.

---

## 4. The tooling

### 4.1 Two tool families — pick the right one

They cut the same 37 files along different axes, and reaching for the wrong one is how
people end up hand-editing bundles.

| | `scripts/l10n-ai.js` | `scripts/l10n/*` |
| --- | --- | --- |
| Works on | **one key, across all locales** | **one locale, across all keys** |
| Use it for | a source change added/renamed/removed a string; a single wrong value | a translation pass |
| Writes | in place, per key | only via `apply.js`, in gated batches |

**`node scripts/l10n-ai.js <sub>`** — the per-key tool. Operates on `l10n/*.js` only;
never touches the backend `.json`.

| Subcommand | What |
| --- | --- |
| `has <key> [--ignore-case]` | does this key exist? |
| `get <key>` | print its value in every locale — the fastest way to answer "has any locale ever translated this differently?" (§3.3) |
| `find <substring>` | list keys containing a substring, case-insensitive |
| `add <key> --value <lang>=<text> …` | add a key. One `--value` per locale, `--locales=a,b` to narrow |
| `set <key> --locale=<lang> --value=<text>` | update a single locale |
| `rm <key> [--force]` | remove a key everywhere |
| `rename <old> <new> [--force]` | rename a key everywhere |
| `list-locales` | the shipped locale list |

Three gotchas, all load-bearing:

- **`rename` does not rewrite call sites.** Grep `src/` afterwards, or `test:l10n` will
  fail on the call site that still uses the old string.
- **`set` refuses pluralised (array) keys.** Those go through `apply.js` with a full array.
- **It refuses to overwrite an existing real value without `--force`. Trust the refusal**
  (§3.8).

### 4.2 Commands

| Command | What it does | Notes |
| --- | --- | --- |
| `npm run l10n:status -- <loc>` | absent / identical / arity / empty counts, plural header, measured register | start and end of every batch |
| `npm run l10n:worklist -- <loc>` | the worklist as JSON on stdout: **`absent ∪ unjustified-identical`** | redirect to a scratchpad file |
| `npm run l10n:harvest -- <loc> [todo.json]` | candidate values from core + sibling apps | 2–7% hit rate. **Candidates, never answers** |
| `npm run l10n:patchcheck -- <loc> patch.json` | runs the locale's register detector over a patch **before** it lands | catches a register slip while it is still cheap |
| `npm run l10n:apply -- <loc> patch.json [--apply]` | the **only** writer. Six gates; refuses the whole patch rather than landing half | dry-run by default |
| `npm run l10n:selfcheck -- <loc>` | 16 assertions, incl. a diff against `HEAD` and a serializer round-trip | must be all-pass before committing |
| `npm run l10n:runtime -- <loc>` | drives the real `@nextcloud/l10n` against the real bundle | the only thing that catches a wrong plural boundary |
| `npm run l10n:gatetest -- <loc>` | proves the parity gate really fails when this locale loses a key | last step of a pass; restores the bundle itself |
| `npm run l10n:script -- <loc>` | script coverage for a **non-Latin** locale: values carrying no target-script character, plus every Latin run | §5 step 8, in place of the English-leftover scan. A reading aid — never fails |
| `npm run l10n:corediff -- <loc>` | every key the bundle shares with core, split AGREE / DISAGREE | **first thing in an audit.** The AGREE list is what you must not "fix" |
| `npm run l10n:termdrift -- <loc>` | English words the bundle renders two ways | the §6.9 term count, over *all* words instead of a guessed list |
| `npm run l10n:spell -- <loc> --suggest` | words absent from a hunspell dictionary | wrong-language stems and typos. Needs `l10n:fetchdicts` |
| `npm run l10n:fetchdicts` | hunspell dictionaries into `scripts/l10n/dicts/` (gitignored) | one-time per machine, and needs the `hunspell` binary first (`pacman -S hunspell`, or the apt/brew equivalent). 30 of 36 locales exist — `fi ga lb mk mt rm` have none, so a quiet spell report there is a **gap, not a clean bill of health** |
| `npm run check:l10n` | developer audit: missing / unused / unwrapped | **`0 missing, 0 unused`** is the invariant; the unwrapped count is a known backlog (§10) |
| `npm run test:l10n` | CI gate: `en.js` covers every `t()`/`n()` call | the coverage gate. `check:l10n` is the richer audit of the same extraction — it adds unused + unwrapped and has no write mode |
| `npm run test:l10n:parity` | CI gate: parity, empty, arity, cognates | |
| `npm run test:l10n:write` | extract new keys into `en.js` | after a source change adds strings |
| `npm run clean:l10n` | keys no source file references — **dry run on purpose** | never run blind; see §8.9 |

`l10n:status` and `l10n:worklist` are both `batch.js` (`status` / `absent`); it is read-only.

Environment overrides, for a checkout that differs from the default layout:
`L10N_WORKSPACE`, `L10N_SERVER_DIR`, `L10N_APPS_DIR`, and `L10N_FINISHED_LOCALES`.

### 4.3 The four CI legs

`check:specs`, `test:l10n`, `test:l10n:parity`, `format`. **All green, and must stay green.**

`npm run lint` is **not** one of the four, and it currently exits non-zero on pre-existing
errors in `src/` unrelated to l10n (`vue/attribute-hyphenation`, `l10n-enforce-ellipsis`).
It also only covers `src/`, so it never sees `scripts/` or `tests/`. Before blaming your own
change, check whether it was already failing:

```bash
git stash && npm run lint; echo "at HEAD: $?"; git stash pop
```

`npm run format` covers `**/*.{js,ts,vue,css,scss}` — which **excludes** `l10n/` via
`.prettierignore`, deliberately. **Never reformat `l10n/*.js`.** `serializeJs` emits the
exact on-disk Nextcloud/Transifex layout; `eslint --fix` would rewrite all ~4400 lines and
diverge from what Transifex regenerates. It also excludes `.md`, so a prettier warning on
`CLAUDE.md` does not fail the leg.

### 4.4 Per-locale files the gates read

Neither of these is documentation. The gates read both.

**`scripts/l10n/locales/<loc>.json`** — write it **before** the first batch.

```json
{
  "register": "formal",
  "registerEvidence": "…how it was measured, with the counts…",
  "buttons": "…the convention, and how it was measured…",
  "pluralNote": "…the boundaries, and any header/library disagreement…",
  "cognates":    { "CSV": "reason, ≥15 chars" },
  "corrections": { "Some key": "why the pre-existing value was wrong" }
}
```

`register` and `cognates` are consumed by the gates. `pluralOrder: "library"` and
`pluralBoundary: "library"` are the two recognised plural acknowledgements, and they are
**not** interchangeable — §6.7. Any other field is free-form documentation and is ignored:
`registerEvidence`, `buttons`, `orthographyNote`, `lexiconNote`, `pluralHackNote` are all in
use.

**A functional field must be added to `loadLocaleConfig`'s whitelist or it is silently
dropped.** This has happened three times — `pluralOrder`, `spellAllow`, `pluralBoundary` —
and it survives because it usually fails in the *safe* direction: a dropped `spellAllow`
makes a report noisier, never wrong, so nothing goes red and nobody looks. **Writing the
rationale in prose is not setting the field.** So verify a new field by making it do
something observable, not by reading the code or the JSON back.

**`scripts/l10n/detectors/<loc>.js`** — the register detector. The gates call exactly two
things, so those are the required exports: **`score(s) -> {f, i}`** and
**`runControls() -> {fail, total}`**. Also export `fold` and `CONTROLS` by convention, and
**`UNDETECTABLE`** — a list of `[example, why]` pairs for informal styling the detector
*cannot* see. That last one is documentation rather than interface, but write it: it is the
honest record of the detector's blind spots, and without it the next reader assumes a clean
scan means clean data. Running the file directly executes its own controls **and** scans
core:

```bash
node scripts/l10n/detectors/<loc>.js
```

Templates: `hr.js` and `sl.js` for formal-prose/imperative-buttons, `et.js` if the locale
turns out informal (its polarity is inverted), `sk.js` if the 2sg imperative is detectable
(§6.5), `bg.js` if it is detectable for only **part** of the verb system, `ro.js` if you need
a label-position bound, and **`rm.js` if core ships nothing for this locale** — it is the
only detector whose `main` block cannot call `scanCoreRegister`, so it re-checks that core
is still empty and scans the bundle instead.

---

## 5. The pass, in order

Twelve steps. Skipping step 2, 5 or 8 is what produces a locale that passes every gate and
is wrong.

```bash
LOC=sr
SCRATCH=/tmp/…/scratchpad        # anywhere outside the repo
```

**1. Status.** `npm run l10n:status -- $LOC`. Note `nplurals`, the plural header, `absent`,
and the identical count. Read the identical list: it usually splits into a block of
genuinely untranslated English (a recently added feature) plus a handful of real cognates.

**2. Measure the register against core.** Build the detector first (step 4) or a throwaway
probe, then:

```bash
node scripts/l10n/detectors/$LOC.js     # controls + core scan in one run
```

Counts markers across `server/core/l10n`, `server/lib/l10n`, `server/apps/*/l10n`. Region
variants are found by matching the directory, so Estonian's `et_EE` and Lithuanian's `lt_LT`
are picked up without guessing the region code. Record the verdict and the counts in
`locales/$LOC.json`. If core comes out **MIXED**, see §6.4.

**3. Establish the button convention separately.** Prose register does not predict it —
there are **five** patterns, one of them graded by length rather than categorical (§7.3).
Measure it from core's own short labels: resolve ~30 bare action keys (`Save`, `Delete`,
`Add`, `Cancel`, …) against core's catalogues and classify the results. Record the counts.

**4. Write `detectors/$LOC.js`** from **closed word lists, never suffix patterns**, with
must-fire / must-not-fire controls that include that language's homograph traps (§8.1–8.3).
Every control should be a real value from this bundle or from core where possible. Aim for
40+ controls; the recent passes run 46–63.

**5. Read the locale's own existing values, and GRAMMATICALLY AUDIT THEM.** All of them — an
unfinished bundle carries roughly half the key set already, and defect rates have run from
2.8% to 22%. This is the single most under-budgeted step: plan for it to take as long as a
translation batch, and see §6.9 for the method. You are also looking for the domain terms —
audit trail, view, chunk, embedding, webhook, flow vs workflow, payload, token, dashboard,
hash, soft delete, `Delete` vs `Remove`, `Type`, `Filters` — and for the locale's
**typographic and orthographic conventions**: ellipsis spacing, dash choice, whether `%`
takes a space, how progressive states are phrased, and **whether domain terms are
capitalised mid-sentence**. Measure that last one per term rather than eyeballing it
(§8.10); in `sr` it decides a third of all values and no gate can check it.

**6. Worklist, then harvest.**

```bash
npm run l10n:worklist -- $LOC > $SCRATCH/$LOC-todo.json
npm run l10n:harvest  -- $LOC $SCRATCH/$LOC-todo.json
```

The worklist is `absent ∪ unjustified-identical`, **every round** — regenerate it between
batches rather than filtering by hand. Harvest writes
`scripts/l10n/harvest-$LOC.json` (gitignored) and prints what it dropped as another
language (§6.6). **Verify every hit at its call site** (§8.4).

**7. Translate in batches of ~250 keys.** Per batch:

```bash
npm run l10n:patchcheck -- $LOC $SCRATCH/$LOC-patch-N.json     # register slip?
npm run l10n:apply      -- $LOC $SCRATCH/$LOC-patch-N.json     # dry run
npm run l10n:apply      -- $LOC $SCRATCH/$LOC-patch-N.json --apply
cp l10n/$LOC.js $SCRATCH/$LOC-WIP.js                           # ALWAYS. See §6.10
node scripts/l10n/batch.js absent $LOC > $SCRATCH/$LOC-todo-N.json   # fresh worklist
```

Add each cognate's justification to `locales/$LOC.json` **as you go** — apply refuses the
batch without it, and it also refuses a recorded cognate that is in neither the patch nor
the bundle, so records and values must land together.

**8. Sweep your own work** before verifying. Cheap, and it has caught something every time:

- **English leftovers** — scan values for English function words (`the`, `and`, `with`,
  `from`, `this`, `will`, `your`, …). Expect one false positive from `{from}`.
- **Foreign orthography** — scan for letters the target language does not have. For
  Slovenian that is `ć đ ě ř ů ą ę ł ń ś ź ż`; it is how the Croatian `trag` was found.
  For Slovak, `ě ř ů ą ę ć ś ź ż`.
- **Non-Latin locales** (`bg sr mk be`): a **script-coverage** check replaces the
  English-leftover check, because for a Cyrillic target the signal is the script rather than
  the vocabulary — an untranslated English value and a correct Bulgarian one are both just
  words.

  ```bash
  npm run l10n:script -- $LOC        # never fails a build; it is a reading aid
  ```

  It prints two lists. **Values with no target-script character at all** should each be a
  recorded cognate or a normalisation (`Url` → `URL`). **Latin runs inside translated
  values** should all be placeholders, product names, acronyms or literal example values;
  read them once and confirm. The script requires the locale as an argument and refuses one
  whose expected alphabet is not recorded in it, since `sr` also ships in Latin in the wild.

  **This finds a defect class the Latin-script locales cannot have**, which is why it
  replaces rather than supplements: a correct translation written in the *wrong alphabet* is
  not empty, not identical to English, not wrong-arity, and reads as finished work to
  anyone skimming. Run it **before** concluding the pre-existing half is sound.
- **Your own typos.** Grep for the near-miss spellings of the words you used most. The `sl`
  pass shipped `namesčen` for `nameščen` in 8 values this way.

**9. Verify.**

```bash
npm run l10n:selfcheck -- $LOC     # must be ALL CHECKS PASS
npm run l10n:runtime   -- $LOC     # must be ALL RUNTIME CHECKS PASS
npm run check:l10n                 # 0 missing / 0 unused
npm run test:l10n && npm run test:l10n:parity
```

**10. Add `$LOC` to `FINISHED_DEFAULT`** in `tests/l10n/check-l10n-parity.js`, then
**negative-test the gate**. Adding a locale to that list is a *claim* that the gate now
holds it; this checks the claim rather than assuming it, because a gate nobody has seen
fail is not known to work.

```bash
npm run l10n:gatetest -- $LOC        # snapshot, break, assert, restore, re-assert
```

It asserts four things — green before, fails **and names this locale** with a key missing,
restores byte-identical, green again — and owns the whole cycle itself, so there is no
window in which you have to remember to put the file back. **Do not do this by hand with
`git checkout`**; see §6.10.

**11. Update the docs — all four places.** Seven consecutive passes bumped `CLAUDE.md` and
left `docs/l10n-ui-translation.md` claiming twelve finished locales while the gate enforced
nineteen.

- `CLAUDE.md` — the count snapshot, the enforced-locale list, and any new plural or
  register fact.
- `docs/l10n-ui-translation.md` — the register list, the button table, the plural list, a
  `### <loc> traps` section, and the "N locales are complete" line. **This is where a pass's
  per-locale findings go.**
- `scripts/l10n/README.md` — the enforced-locale count.
- **this file** — remove the locale from §2.3, and add to §6/§7/§8 **only if the pass
  taught a new rule**. Not the evidence for it, not the counts (§2), not a case study. If
  what you have to say is "on `<loc>` this came out differently", it belongs in the
  companion or the commit message. This file is capped at 800 lines.

**12. Regression-check every recorded locale, then commit.**

```bash
for f in scripts/l10n/detectors/*.js; do l=$(basename $f .js)
  node scripts/l10n/selfcheck.js $l | tail -1
  node $f | grep controls
  node scripts/l10n/runtime-check.mjs $l | tail -1
done
```

Any change to `lib.js` or a shared gate can break a previously finished locale. Then one
commit for the language, with the measurements in the message.

---

## 6. What to do when…

### 6.1 `apply.js` refuses: "value===key with no recorded reason"

Decide the split (§3.2). Genuine cognate → add it to `cognates` with a real justification.
Otherwise → translate it. Do not delete the key to make the message go away.

### 6.2 `apply.js` refuses: "cognate recorded but absent from both the patch and the bundle"

You added the record before the value. Either include that key in **this** patch, or remove
the record until the batch that carries it. This is also why **sequential re-application of
several patches from a clean bundle fails** — later batches' records are missing from
earlier patches. To replay a whole pass, **merge the patches and apply once** (§6.10).

### 6.3 `apply.js` refuses: "would clobber a real value"

Intentional? Name the keys explicitly and record why:

```bash
npm run l10n:apply -- $LOC patch.json --apply -- --allow-replace='key one||key two'
```

It prints every `was:` → `now:` pair. Then:

- **The value was pre-existing (present at `HEAD`)** → add an entry to `corrections` in
  `locales/$LOC.json`. `selfcheck` compares against `HEAD` and will fail without it.
- **The value was written earlier in this same pass** → no `corrections` entry needed,
  because the key is absent at `HEAD`. `apply` will still print a NOTE suggesting one;
  that suggestion is wrong for this case. Mention the fix in the commit message instead.

### 6.4 The core register scan says MIXED

Core is genuinely inconclusive; that is a finding, not a failure. Procedure:

1. Fall back to the **bundle's own** pre-existing keys and count those.
2. If that is also unclear, or the choice is a product decision rather than a linguistic
   one, **ask the owner** — this is the one place in the pass where approval is right.
   Record the answer, and *who* decided, in `registerEvidence`.
3. Note the direction in the docs. For `et` and `lv` core was one-sided and **overruled**
   the file; for `ro` core was inconclusive and the **file won**. Same rule, opposite
   outcomes.

If the scan **throws** "no `<loc>` catalogues found", check whether the layout is wrong
before assuming it is: for some locales there is genuinely nothing to scan. It throws on
purpose either way — it used to scan zero files and print `verdict: MIXED` computed from
nothing.

- **Layout differs** → set `L10N_SERVER_DIR`.
- **Core really ships nothing for this locale** → fall back to the bundle's own values, and
  **say so explicitly in `registerEvidence`**; a verdict from the app's own file is weaker
  evidence than core, and the record has to show which one it rests on. The detector's
  `main` block then cannot call `scanCoreRegister` — copy `detectors/rm.js`, which
  re-checks that core is still empty rather than asserting it from a comment.

  **Widen the fallback corpus to the sibling apps' FRONTEND bundles.** This tripled `mt`'s
  evidence from 1015 values to 3422 and turned a thin 26-vs-0 into 128-vs-0. Two
  constraints, both load-bearing: include only the **`.js`** bundles, because the backend
  `.json` is a separate catalogue with a separate consumer (§1) and its markers would be
  miscredited to the frontend; and exclude byte-identical mislabelled catalogues the same
  way `harvest.js` does (§6.6).

### 6.5 Deciding whether the 2sg imperative is detectable

Most locales must **exclude** the bare 2sg imperative from the detector. Two independent
tests, and exclusion follows from **either**:

1. Is the imperative the locale's own **label convention**? If yes, counting it flags every
   button in the app. (`ca`, `et`, `hr`, `sl`, `sr`, `ga`, `mt`, `bs`: yes.)
2. Is the imperative a **homograph** of a form that is live in this bundle's prose — the 3sg
   present, a past tense, a verbal noun, a participle, or an ordinary noun? If yes, counting
   it flags ordinary third-person prose.

**Run both; the answers are independent and neither predicts the other.** `sk` fails both,
so it counts imperatives — labels are infinitives and `ulož` ≠ `uloží`. Its immediate
neighbour `sl` passes both. `rm` shares `sk`'s infinitive labels (test 1 same) and fails
test 2 outright. So do not infer one test from the other, and **do not carry either answer
across from a neighbour** — this is about the data, not the language family.

If the imperative is a homograph but you still need to catch it in *labels*, use a position
bound: string-initial plus a length cap (`ro.js` uses 40 characters).

**There is a third outcome: PARTIALLY detectable, and it is free precision — look for it.**
The paradigm can fail for only *part* of the verb system, in which case enumerate the usable
class and record the rest in `UNDETECTABLE`. Three splits seen so far, by **conjugation
class** (`bg`: а-conjugation imperatives are unambiguous, и-conjugation ones collide with
both the 3sg present and the aorist; `is`: class-1 and strong verbs are distinct, class-2
verbs are identical to the 3pl past), and by **lexical class** (`lb`: the modals syncretise
1sg/2sg/3sg and carry no address information, while regularly inflected 2sg forms of the
same verbs are usable). **So when a locale fails test 2, check whether it fails for every
class before excluding the whole paradigm** — the two informal slips already shipped in `bg`
were both in the detectable class, so a wholesale exclusion would have found neither.

**A BIGRAM can rescue two individually-ambiguous tokens at once.** Where a locale has a
pronoun and a verb form that are each ambiguous but not jointly so, match the pair: `is`
counts bare `þér` as informal (it is overwhelmingly the 2sg dative) and the pair
`þér` + finite 2pl verb as formal, in both orders since a question inverts it. This recovers
recall a per-token closed list has to throw away.

One caveat that comes with counting any imperative: it measures against **this bundle's**
label convention, not core's — core `bg` uses imperatives as labels in 29 places while the
bundle uses verbal nouns throughout. Say which one it is measuring in `locales/<loc>.json`.

### 6.6 `harvest.js` prints "DROPPED … identical to another locale"

A sibling app's catalogue for this locale is byte-identical to its catalogue for a different
language, so it cannot be this language's translation. Expected and correct for the
**openbuild** group: one Croatian catalogue ships under seven names —
`bs cs hr mk sk sl sr` — plus `da == sv` and `de == lb`. Nothing to do; the guard already
dropped it. Every hit from that file reads as a plausible Slavic translation of the right
key, which is exactly why a call-site check would not have caught it.

If it drops something you believe is legitimate, check the **base language** comparison —
the first version of this guard compared locale *names* and reported core's
`et_EE.js == et_EE.json` as a mislabel, killing 33 of 40 sources for `et` and `lt`.

### 6.7 `runtime-check` FAILS on which index each count selects

The file's `plural=` expression and `@nextcloud/l10n`'s own `getPlural` disagree about
**which form index** a count selects. The library is what renders, always. There are
**three** kinds of disagreement and they take different remedies, so read which one the
check names rather than reaching for a remedy by reflex.

**A PERMUTATION disagreement** — the two partition the counts into the same groups and only
label them differently. Reordering the arrays makes the locale fully correct:

1. Order the **arrays** by the library, not the header.
2. Record `"pluralOrder": "library"` in `locales/$LOC.json`.

Only `lv` is this: its header carries the legacy gettext order `[one, other, zero]` while the
library partitions `[zero, one, other]` — same three categories, rotated, so a file matching
its own header was wrong at **every** count while passing every other gate.

**A BOUNDARY disagreement** — the two draw the lines in different *places*, so **no
permutation can agree with the header everywhere**. There is nothing to reorder; what you
choose is *which counts to be correct for*:

1. Write each form to read correctly across the counts the library **actually routes to it**,
   weighting by which counts a user plausibly hits.
2. Say in `pluralNote` **which counts stay wrong**, explicitly.
3. Record `"pluralBoundary": "library"` in `locales/$LOC.json`. Writing the rationale in
   prose is not setting the field (§4.4).

`is` and `mk` are both this, in **opposite directions** — which is the reason to classify
rather than pattern-match. On `is` the library reaches form 0 only at exactly 1, so 17 counts
in 0–200 render the plural where Icelandic wants the singular; form 1 stays the true plural
because contorting it would trade 17 wrong counts for ~180 unidiomatic ones. On `mk` only 11
and 111 disagree and they go the other way, so the residue is two counts.

**The shape to check for is a two-form header whose expression is modular rather than
`n != 1`**, since the library's coarse groups are mostly written `n === 1`. Plain `n != 1`
headers and three-form Slavic ones have all agreed exactly.

**A NOTE that the library uses FEWER forms than declared** (`tr`, `rm`, `ga`) is the third
situation, and it is harmless **only when a single form is correct in that language anyway**.
The two cases look identical in the tool output, so **decide it by measuring core, not by
reasoning about the grammar**: `tr` and `ga` are harmless, while `rm` is not — the library
knows no Romansh and returns 0 at every count, but Romansh pluralises regularly with `+s`, so
a bare singular in form 0 renders `5 datoteca`. Nothing flags it: the arity is right, no value
is empty, and the file reads fine.

Where the collapsed form is not correct on its own, **the fix is in form 0, not in the
arity** — a shape correct at every count, such as the `(s)` parenthetical, or a
number-neutral phrasing where a parenthetical cannot span three agreeing words. Write form 1
as the true plural anyway, so the array becomes correct if the library ever gains an entry.
Do **not** add `pluralOrder`; there is no ordering disagreement here. This applies to
pre-existing arrays too — `rm`'s carried a singular noun **and** verb in form 0, wrong twice
at every count but 1, and had passed every gate for the life of the file.

**A locale can also come out with no plural surprise at all.** Do not read that as "the
arrays are easy": `mt`'s four forms are Semitic rather than European (§7.1) and its one
flagged "suspect array" turned out **correct**. Run the check to find out which situation you
are in; do not infer it from the header's shape.

### 6.8 `selfcheck` reports a stale cognate, or a NOTE

- **"stale cognate record"** — the recorded value is no longer identical to its key, so the
  record is a standing permission slip for whatever gets written there next. Remove it.
  One subtlety: a **plural** key counts as in-use if **any single form** equals the source
  form. Romanian's `["{count} email", "{count} emailuri", "{count} de emailuri"]` is a real
  cognate for the singular only; `hasIdenticalForm` in `lib.js` is what reconciles the three
  gates that used to disagree about it.
- **NOTE "plural arrays with every form identical"** — report, not failure. Confirm each is
  genuine: Hungarian does not pluralise after a numeral, Swedish `objekt` is invariant, and
  a key with no `{count}` legitimately reads the same for several counts.
- **A `SKIP` or `NOTE` is not a bug to tighten away.** "Identical to English" and "rendered
  the English source" are the symptoms of the two worst defects this tooling catches *and*
  of a perfectly legitimate cognate. The justification record is what separates them.

### 6.9 The pre-existing values — AUDIT THEM ALL, then fix what is wrong

**This is a required step of every pass**, not something you do when you happen to notice
something. None of what it finds is visible to any gate: a wrongly-inflected value is not
empty, not identical to English, not wrong-arity, and reads as finished work. §3.8 guards
against changes of taste, not against fixing grammar — do not cite it to skip the audit.

**Run the three reports before reading anything.** Measured against `sk`'s corrections they
reach ~48 of 57 before you read a value:

1. `npm run l10n:corediff -- <loc>` — **first.** Its AGREE list is the set of values you
   must never question; its DISAGREE list is where the real terminology decisions are.
2. `npm run l10n:termdrift -- <loc>` — competing renderings per English term, over *every*
   English word instead of a guessed list. This is the single highest-yield report: ~70 of
   113 on `cs`, 37 of 57 on `sk`, and it needs no knowledge of the language. **It reports
   the MINORITY side, and the minority is not automatically the defect** — on both `sk` and
   `sq` the minority reading was the right one.
3. `npm run l10n:spell -- <loc> --suggest` — wrong-language stems and typos. Needs the
   `hunspell` binary plus `npm run l10n:fetchdicts`, once per machine; `fi ga lb mk mt rm`
   have no dictionary, which is a known gap and not evidence those locales are clean.
   **Read it for tooling failure before you read it for defects**: a cluster of implausible
   short words sharing a stem with a real one means the tokeniser split something (§8.3).

Then the **collision scan** — `grep -rn "tabs:" src/ -A4`, checking each sibling pair
against the bundle, and the paired empty states the same way. A byte-identical collision the
user can see on one screen is a defect however defensible each word is on its own, and it is
not findable by reading values one at a time (§8.5). Then the dangling-preposition sweep,
then **read every remaining value**.

**Do not build mechanical morphology checks.** Yield has been 4 of 239 on `is` and ~0 of 113
on `cs`; `sk` skipped them entirely and lost nothing. The one exception is an *orthographic*
rule with a deterministic trigger — §8.11. **Do not rebuild the cross-locale outlier
scanner** either: clustering all 36 locales per key and flagging the odd one out scored
recall 1 of 57 and precision 1 of 65, and was deleted. `node scripts/l10n-ai.js get <key>`
answers the same question on demand.

**Read the bundle as a subagent fan-out, not sequentially.** Slice the listing into ~4
chunks, spawn one subagent per chunk in a single message, and give each the same shared
context: the `corediff` AGREE list, the `termdrift` output, the collision list, and the
locale's register + button convention (§7.2/§7.3). Without that they re-derive candidates
core already killed and flag every infinitive button as a register slip. Subagents return
**candidates with call-site evidence, never verdicts** — adjudicate centrally, which is where
the errors are (11 of ~30 on `sk`). A second fan-out on `sk` found 5 defects the first
sequential read missed, so this raises recall and not just speed. **Cheap models may generate
candidates but never decide**: a Haiku-class reader gave 1 real finding and 2 confidently
wrong ones that would have written new errors into correct Slovak.

**CHECK CORE AND THE CALL SITE BEFORE CALLING A VALUE WRONG.** This overturned four
candidates on `cs` and eleven on `sk` — more than any single class either pass corrected —
and on `ca` it killed two collisions that looked identical to the two real ones. A collision
core also has is not a defect. A majority inside the bundle is not authority on its own; a
lone outlier is sometimes the only value that matches core. And the call site decides the
sense: `Handler` is a **person** in the DSAR cases table, and an infinitive among noun
siblings is correct when the `<h3>` sits over filter *controls*.

**Read converse pairs against each other.** On `sk`, `Uses` and `Used by` were **swapped**.
Nothing catches this — not a gate, not the term count, which correctly reports both using
the right stem. Check Uses/Used by, Parent/Child, Source/Target, Merged from/into. Sibling
locales settle the direction fast.

Record every correction in `corrections` (§6.3). At volume, per-key prose stops being
readable — use **short class codes** (`AGREEMENT`, `CASE`, `NUMBER`, `COMPOUND`, `HYPHEN`,
`TYPO`, `GARBLED-OR-FOREIGN`, `SENSE`, `TERM-*`, `CONSISTENCY`) and document the codes once
in a free-form field, as `locales/is.json` does.

Three judgement calls:

- **Register deviations are not optional cleanup.** Leaving them makes the bundle mix
  registers inside a single dialog, which reads worse than either choice made consistently.
- **A mild inconsistency is not automatically a defect.** `hr` kept `lozinka` over core's
  preferred `zaporka` because the file already shipped it and it is valid Croatian.
- **An escalated decision can come back "change nothing", and that must still be recorded**
  as a *deliberate* divergence with its counts. An unrecorded "left alone" is
  indistinguishable from "never looked".

**A finished audit is not proof the locale is clean, and a `corrections` count of 0 means
"unverified".** Record the count, never a verdict. §9.2 has what to budget per locale.

### 6.10 You destroyed the working bundle

`l10n/<loc>.js` is **uncommitted for the entire pass**, so `git checkout -- l10n/<loc>.js`
does not undo your last step — it reverts to pre-pass `HEAD` and discards everything. This
happened on `sk` and threw away 999 applied values.

Recovery: the per-batch patch JSONs in the scratchpad are the source of truth. **Merge them
into one and apply once** — sequential re-application fails for the reason in §6.2:

```bash
node -e 'const fs=require("fs"),d="'$SCRATCH'";const out={};
for(const i of [1,2,3,4]){const p=JSON.parse(fs.readFileSync(`${d}/sk-patch-${i}.json`));
for(const [k,v] of Object.entries(p)){if(k in out) throw new Error("dup: "+k); out[k]=v}}
fs.writeFileSync(d+"/all.json",JSON.stringify(out,null,1)+"\n")'
npm run l10n:apply -- $LOC $SCRATCH/all.json --apply
```

Then confirm the totals match the sequential run.

Prevention: `cp` after every batch, and restore with `cp`, never with git. The one place
this hazard used to be unavoidable — the gate negative test — is now designed out:
`l10n:gatetest` snapshots and restores the bundle itself.

### 6.11 A harvested value looks right but is the wrong sense

Read the call site. See §8.4 for the confirmed offenders — this is the single most
productive check in the whole pass.

### 6.12 `patchcheck` reports informal (or formal) markers in your patch

Fix the **values**. Do not loosen the detector to make the count go away. If the flagged
value is genuinely correct and the detector is wrong, the detector needs a new control and a
recorded reason — and that is a separate, deliberate change.

### 6.13 A detector control fails

Almost always: the word is not in the closed list. Add it. Do **not** replace the closed
list with a suffix pattern — §8.1 is a catalogue of why that fails. If the control itself
was wrong, delete the control and say so in a comment.

### 6.14 The `format` leg is red

`npx prettier --write` the specific files you touched. Never `l10n/`. If it is a `.md` file,
check whether the leg actually covers it (§4.3) before touching it — reformatting
`CLAUDE.md` wholesale creates a huge unrelated diff.

### 6.15 The source added, renamed or removed an English string

This is the most common way `test:l10n` or `test:l10n:parity` goes red **without anyone
touching l10n**, because a development merge that adds one `t()` call puts every finished
locale one key short.

```bash
npm run test:l10n            # names the keys en.js is missing
npm run test:l10n:write      # extracts them into en.js
```

Then, for each new key, make the §3.2 decision **before** translating anything:

- **Non-prose** (a placeholder, an example value, a bare product name) → **unwrap it in
  `src/`** and do not add the key at all. If `test:l10n:write` already added it, remove it
  from `en.js` too. This is the right answer more often than it looks.
- **Real prose** → it needs a value in **every finished locale**, or parity breaks. Use
  `l10n-ai.js add <key> --value en=… --value nl=… …`, or a small `apply.js` patch per
  locale. Check `l10n-ai.js get <similar-key>` first — a sibling key often already tells you
  each locale's term.

For a **rename**, `l10n-ai.js rename` handles all 37 bundles but **not the call site** —
grep `src/`. **Prefer a rename to an add whenever the English wording changed for a string
that already exists**: every finished locale's translation survives it and parity never
breaks. For a **removal**, `rm` the key everywhere in the same commit, or `check:l10n`
reports it unused forever.

---

## 7. Per-locale reference data

`docs/l10n-ui-translation.md` is the full version, with the evidence and the per-locale
trap sections. This is the part you need in front of you while writing arrays.

### 7.1 Plurals

Take `nplurals` from the locale file's **own** header and build against **that locale's
expression**. Equal form counts do not mean equal boundaries.

| Locale | Forms | Boundaries |
| --- | --- | --- |
| `hr` `ru` `sr` `be` `bs` | 3 | modular: `1,21` / **2–4** / `0,5–20` |
| `lt` | 3 | modular, **wider form 1**: `1,21` / **2–9** / `0,10–20` |
| `pl` | 3 | modular, `n==1` exact for form 0 |
| `cs` `sk` | 3 | **absolute**: `1` / `2–4` / everything else incl. 0 |
| `lv` | 3 | `1,21` / nonzero / **dedicated zero form** — and the ORDER disagrees (§6.7) |
| `ro` | 3 | `1` only / **0 and 2–19** / 20+ |
| `sl` | **4** | modular on `n%100`: `1` / **2 = DUAL** / `3,4` / else incl. 0 |
| `is` | 2 | modular header, but the library reaches form 0 **only at n=1** — a BOUNDARY disagreement no reordering fixes (§6.7) |
| `mk` | 2 | same modular header as `is`, but the library **drops the `n%100!=11` guard**, so only 11 and 111 disagree, in the opposite direction (§6.7). **No counted form** — the `-а` form survives only in a closed set of measure nouns, so `bg`'s hazard below does *not* transfer |
| `ga` | 5 | header 5, library reaches **3**: `1` / `2` / `0` and all `n>=3`. Forms 3–4 dead, harmlessly (§6.7). What decides the arrays is the **counted-noun rule** below |
| `mt` | 4 | **Semitic, not European**: `1` / `0 and n%100 2–10` / `n%100 11–19` / `20+`. The noun is PLURAL only in form 1 — forms 0, 2 and 3 all take the SINGULAR, so three near-identical forms are correct, not a defect |
| `bg` | 2 | plain `n != 1` — but see the **count form** hazard below |
| `lb` `sq` | 2 | plain `n != 1`, header and library agree at every count |
| `rm` | 2 declared | the library knows no Romansh and returns **form 0 at every count**, so form 1 is unreachable — see the collapsed-form hazard below |

The distinct hazards, every one of which has actually bitten:

- **Wrong boundary.** A Croatian array pasted into Lithuanian is wrong for 5–9.
- **The library's boundaries not matching the header's**, with no reordering available to
  reconcile them — §6.7.
- **Absolute vs modular.** `sk`/`cs` bound absolutely, so **22 selects form 2**; `hr` bounds
  modularly, so 22 selects form 1. An array copied between two `nplurals=3` Slavic locales
  is wrong at every compound number.
- **The same header AND the same boundaries can still need a different NOUN form.** `be`'s
  expression is byte-identical to `ru`'s and partitions the counts identically, and Belarusian
  still takes the **nominative plural** after 2–4 where Russian takes the genitive singular
  (`2 запісы`, not `2 записа`) — with the adjective and verb going plural alongside it. So
  matching headers is not even evidence for copying form 1, let alone the whole array. Core
  decides it; core `be` is one-sided across all 30 of its arrays.
- **The dual.** Slovenian's form 1 governs noun case, adjective agreement **and verb
  number** together: 2 needs `Objekta sta bila izbrisana` where the plural needs
  `Objekti so bili izbrisani`.
- **A separate counting form, chosen by the SENTENCE and not by the form index.** Lexical
  rather than structural, so `nplurals` gives no warning — `bg` has the simplest header in
  the set and still needs it. Bulgarian masculine non-person nouns take a count form
  (`-а`/`-я`) after a numeral, distinct from the ordinary plural, so **which noun form form
  1 needs depends on whether the string contains a numeral**:

  ```js
  "_Delete {count} object_::_Delete {count} objects_":            // numeral present
      ["Изтриване на {count} обект", "Изтриване на {count} обекта"]
  "_Object successfully deleted_::_Objects successfully deleted_": // no numeral
      ["Обектът е изтрит успешно", "Обектите са изтрити успешно"]
  ```

  Masculine **person** nouns keep the plural; feminine/neuter have no count form at all.
  Look for this in any locale with a paucal/counting form — it is invisible to every gate.

- **The numeral forcing the SINGULAR — the mirror image.** Irish takes the singular noun
  after a numeral, so for `ga` the split is again decided by whether the value contains a
  numeral, in the opposite direction: **numeral present → the same counted singular in every
  form; no numeral → a genuine singular/plural split.** Core separates cleanly on exactly
  that axis (53 of 73 numeral-bearing arrays keep the singular; 28 of 28 without a numeral
  pluralise), which is how the rule was settled rather than assumed. Initial mutation is
  **not** applied after a digit, but a *fixed* numeral in a label does take it — the
  difference being whether the number is known at authoring time.
- **The predicate agrees, not just the noun.** `mt`, `lb` and `sq` all have arrays where the
  verb inflects with the count (`%n entrata għadha` / `%n entrati għadhom`; `%n Antrag huet`
  / `%n Anträg hunn`). An array here cannot be built by swapping the noun alone.
- **The library collapses every count onto form 0, in a language that pluralises** (`rm`).
  Same *symptom* as `tr`, opposite consequence — §6.7 for how to tell them apart, and where
  the fix goes.

`test:l10n:parity` catches wrong **length** only. Nothing catches a wrong boundary except
`l10n:runtime` on this locale's own counts, and **nothing at all** catches a form 0 that
is only correct at count 1.

**The `n()` catalogue key is NEITHER source string.** It is the identifier
`"_<singular>_::_<plural>_"` — see `pluralIdentifier` in `lib.js`. Storing forms under the
bare singular renders correctly for `count === 1` and falls back to English for every other
count. That shape shipped in all 37 bundles until it was fixed, passing every gate.

**At runtime the index comes from the library, not the header.** `register(app, bundle)`
**ignores** a plural function passed as a third argument. The header governs the arity gate;
the library governs which element renders. `runtime-check.mjs` calls `unregister()` and
`setLanguage(loc)` first for this reason.

### 7.2 Register verdicts

Informal: `nl de sv da nb pl fi hu et lv ga mt is`.
Formal: `fr cs ru uk tr el sr bg ca hr lt ro sk sl lb sq mk be bs`.

The counts and how each was measured are in `locales/<loc>.json` and in the companion doc.
What belongs here is the taxonomy, because **the label is the same and the situation is
not** — five states have turned up behind these two words, and they differ in what a slip
looks like and therefore in what the detector must gate:

| State | Example | What the gate is catching |
| --- | --- | --- |
| no T-V distinction exists | `ga` | 2pl address, which is only ever a defect here |
| has one, measured unused | `mt` | genuine deference — a live option the project declines |
| had one, abandoned it | `is` | archaic deference **and** the plain modern plural, which is the *likelier* slip |
| live and ordinary | `cs` `lb` `mk` | the plain measured choice, no structural story |
| core inconclusive, decided by the file or the owner | `ro` | whatever was decided, recorded in `registerEvidence` |

Two measurement rules that generalise:

- **Split the informal count by what the hit IS before reading a verdict.** On `bg`, 29 of
  43 informal hits were core using an imperative as a *button label*; only 11 were informal
  prose, clustered in two old catalogues. Unsplit, 43 looks like a real minority position;
  split, it is a label-style choice plus eleven legacy strings. This matters most where the
  scan comes out close — exactly the shape that gets escalated to the owner.
- **Low counts can be structural rather than weak.** Most correct informal Latvian is
  undetectable by design, so for `lv` **zero formal markers is the assertion that matters**,
  not a high informal count.

### 7.3 Button conventions — five patterns

| Pattern | Locales | Example |
| --- | --- | --- |
| same register as prose | `tr` `ru` | formal imperative |
| bare 2sg imperative, whatever the prose | `ca` `et` `hr` `sl` `sr` `ga` `mt` `bs` | `Desa`, `Salvesta`, `Spremi`, `Shrani`, `Сачувај`, `Sábháil`, `Issejvja`, `Sačuvaj` |
| **infinitive — register-neutral** | `cs` `lt` `lv` `sk` `rm` `is` `lb` `be` | `Zobrazit`, `Įrašyti`, `Saglabāt`, `Uložiť`, `Memorisar`, `Vista`, `Späicheren`, `Захаваць` |
| **verbal noun — register-neutral** | `ro` `bg` | `Salvare`, `Adăugare endpoint`; `Запазване`, `Добавяне на крайна точка` |
| **2sg imperative for a label, 2pl once it is a sentence — GRADED BY LENGTH** | `sq` `mk` | `Ruaj` / `Fshi` / `Shto`, but `Menaxhoni regjistrat …`; `Зачувај` / `Избриши`, but `Управувајте со вашите апликации …` |

Infinitive buttons must **not** be "corrected" to imperatives.

**Check whether a locale's apparent convention is really two populations before recording
one answer.** The fifth pattern is the only non-categorical one and it has now been measured
twice, with the crossover landing in the same place both times, **~40 characters**. Above it
the long strings are not "labels" at all — they are ordinary prose that happens to open with
a verb, so the register (§7.2) governs them and the button convention governs only the short
end. A single ratio over all action-verb keys would have reported `sq` as 476:145 "mostly
2sg" and produced a 2sg rendering of a 40-character sentence that no sibling app writes.
(`ro.js` already used a 40-character cap as a *detector* trick; the same boundary turning out
to describe the convention itself is why the trick generalises.)

**A lexical override can sit on top of the gradient, and it is lexically bounded rather than
"any prompt".** In both `sq` and `mk`, `Select`/`Choose`/`Enter` placeholder prompts take the
2pl at any length — but `Search` goes the other way and is not close (core `mk` 61:1 for the
2sg). The distinction that predicts it is neither length nor prompt-ness: a dropdown you pick
from and a field you type into address the user, a toolbar button is something you press.

**But WHICH lexemes are inside the override differs per locale, so measure it per verb and
never carry the set over.** `bs` is a categorical pattern-2 locale with no gradient at all,
and it still has the override — splitting it *between* `Select` and `Choose`, which `mk`
groups together: `Select …` takes the 2sg at 14–45 characters (20 of 20) while `Choose …` and
`Enter …` take the 2pl at 15–128 (5 of 5 each). Three locales, three different partitions of
the same small verb set. A `Please …` frame forces the 2pl independently, and a prompt
embedded in prose follows the prose.

`ro` is the only locale where the convention is a **project decision that knowingly diverges
from core**, and it was forced: `Create`/`Read`/`Update`/`Delete` are single keys rendered
both as `<th>` column headers *and* as buttons, so a header reading "delete!" above a count
column would be wrong. `bg` reaches the same verbal noun for an ordinary §3.5 reason instead
— Bulgarian has no infinitive, core is genuinely mixed, and the file's 1053 pre-existing
values broke the tie. Keep the two straight: one contradicts core, the other follows the file.

### 7.4 The `{plural}` source hack — KNOWN DEFECT, note it and move on

**The owner is aware of this and will fix it once all translations are finished** (decided
2026-08-19). So: pick a reasonable shape for the locale, record it in `pluralHackNote`, and
**do not spend pass effort on it**. Do not weigh parenthetical against slash against bare
plural noun-by-noun, do not treat an awkward rendering as a defect worth escalating, and do
not flag it in the commit message. The same applies to the sibling `(s)` keys
(`register(s)`, `schema(s)`, `configuration(s)`) and to plain `{count} X` phrases.

The source hardcodes English morphology: 13 call sites pass `plural: count !== 1 ? 's' : ''`
for five keys. What the finished locales did, kept only so you can pick a shape quickly:

| Shape | Locales | Example |
| --- | --- | --- |
| keep the placeholder — plural really is `+s` | `es`, `ca` (4 of 5) | `fitxer{plural}` |
| parenthetical | `nl de fi ru pl cs et sk sl` | `bestand(en)`, `súbor(y)` |
| slash, where the stem changes | `fr`, `ca`, `et` | `journal/journaux` |
| bare noun — no plural after a numeral | `hu` `tr` `ga` | `fájl`, `dosya`, `comhad` |
| parenthetical AND slash, mixed **per noun** | `is` | `skrá(r)`, but `hlutur/hlutir` where the stem changes |
| the form correct for the most counts | `hr` `lv` `ro` | gender-dependent |
| genitive plural, conventional invariant counter | `lt` | `failų` |

Three rules that override a quick pick:

- **Reuse the bundle's own house style if it has one** — `sk` and `sl` already had
  `register(-tre)` / `register(-i)` for the sibling `(s)` keys.
- **But grammar outranks the house style**, and the call site decides: every `{plural}` call
  site renders the label beside a numeral, so in a language that takes the singular after a
  numeral a parenthetical is *wrong* rather than merely unidiomatic (`ga`).
- **A locale can legitimately need more than one shape, chosen per noun** (`is`): the
  parenthetical works where the plural is stem + `-r`, and produces a non-word where the
  stem changes.

Always runtime-assert no `{plural}` residue and no stray trailing `-s` survives. And note
that a locale which **keeps** `{plural}` is not broken — `es` keeps it in all five, `ca` in
four — so any assertion about those keys must branch on whether the placeholder survived:
kept → the value **must** vary with count; dropped → it **cannot**.

---

## 8. Traps catalogue

### 8.1 Never use suffix patterns for register detection

**Use closed word lists. Always.** Every suffix that has looked like a register marker has
turned out to be something else as well, and the per-language table is in the companion doc.
What generalises:

- **A suffix rule can invert the verdict outright, not merely add noise.** The 2sg `-š` of
  `hr`/`sk`/`sl`/`mk` is also `vaš` = your-**formal**; `cs`'s 2sg imperatives are each a
  prefix of their own 2pl (`vyber` ⊂ `vyberte`); `sq`'s bare `do` is also the future
  particle, 101 occurrences of ordinary third-person prose. In each case the rule scores the
  commonest *opposite-polarity* shape in the corpus as its own polarity.
- **In any language whose 2pl is the 2sg plus a suffix** the trailing `(?!\p{L})` guard is
  not hygiene, it is the only thing separating the two polarities. **Write the guard before
  the word list**, not after a control fails. This looked like a West Slavic property and is
  not one: Belarusian builds its 2pl imperative the same way (`выберы` → `выберыце`), so
  check the morphology rather than the branch of the family tree.
- **Check whether a marker is a substring of the app's own commonest nouns.** `cs`'s
  `nastav` sits inside `nastavení`, and `tvá` inside `vytvářet` — 48 occurrences, every one
  inside that verb.
- **The commonest homograph class is inflectional**: a verb ending that is also a plural,
  a definite article, or a case ending. `-те` is the Bulgarian and Macedonian **definite
  plural article**; `-ið` is the Icelandic neuter definite article; `-ni` is the Albanian
  definite singular of every masculine `-n` stem. These are among the most frequent
  morphemes in their languages, so the rule fires on nearly every noun phrase in the app.
- **Exclusion can be partial** — by conjugation class or by lexical class (§6.5). Check
  whether the paradigm fails for *every* class before excluding it wholesale.
- **Look for the locale's politeness FORMULA, not just its pronouns.** A "please" that
  inflects for the addressee is a free marker: `mt`'s `jekk jogħġbok` carries a 2sg object
  suffix and at 35 uses was the second commonest marker in the bundle, with `jogħġobkom` the
  unambiguous deferential counterpart. Two of the three locales checked so far came out
  empty (`ga`'s `le do thoil` inflects nothing detectable; Icelandic `vinsamlegast` is an
  adverb), so **check rather than assume in either direction** — it is cheap and the payoff
  when it lands is large.

### 8.2 Pronoun homographs, per language

The per-language table is in the companion doc. The rules:

- **Do not port an exclusion across a family without measuring it.** Bare informal `ti`/`ty`
  collides with a demonstrative in `cs`/`hr`/`sl` and must be left unmatched — but the bare
  pronoun is **fully usable** in `bg`, `rm`, `ga`, `mt`, `is`, `lb` and `mk`. Six against
  three, so the collision is the exception rather than the rule. The sharpest case is one
  word: `bg`'s `те` is the 3pl pronoun *they* and must be excluded, while `mk`'s `те` is
  2sg address and is safe, because Macedonian's *they* is `тие`. **Measure the recall you
  would give up before mourning it** — sometimes the excluded token occurs zero times.
- **`si` needs its reason, not just its verdict.** In `hr`/`sk`/`sl`/`bg` it is left
  unmatched because it is *ambiguous* (2sg of *to be*, and a reflexive clitic commonest in
  formal prose). In `cs` it is left unmatched because it is *empty* — Czech's 2sg is `jsi`,
  so `si` is only ever reflexive and carries no address information at all. Same handling,
  opposite reason; `jsi` itself is unambiguous and **is** matched.
- **Case can be the only thing separating the two polarities.** Then `fold()` must not
  lowercase: `lb`'s `dir` is the informal dative and `Dir` the polite nominative, both
  attested, so `detectors/lb.js` normalises whitespace and nothing else. `da`/`nb` `De` and
  `pl` `Państwo` require a mid-sentence capital for the same reason. The residue — a value
  *opening* with the informal form takes a sentence-initial capital and becomes
  indistinguishable — goes in `UNDETECTABLE` rather than being papered over.
- **An ACRONYM can be a homograph of a marker.** `mk`'s `ВИ` is *AI* (вештачка
  интелигенција) and the bundle uses `ВИ-агент`, while `ви` is the formal dative clitic — so
  a folding detector scores the app's AI vocabulary as deference. `detectors/mk.js`
  **consumes the all-caps form in `fold()` before lowercasing**, which is the `lb` problem
  from the other end: `lb` must preserve case throughout, `mk` needs it for one token and
  can spend it up front, keeping the word lists readable.
- **An impersonal or 1pl form carries no address at all.** `bg` `трябва`, `mk` `треба` and
  `молиме` are not markers; in `Ви треба` and `Ве молиме` the register is carried by the
  clitic, and in `треба да се случува` there is no addressee.

### 8.3 JavaScript and Unicode traps

- **`\b` is ASCII-only.** It treats `á`, `č`, `š` as boundaries, so `Alege\b` matches inside
  `Alegeți`. Use `(?<!\p{L})…(?!\p{L})` with the `u` flag, always.
- **Never leave `g` on a reused regex.** `lastIndex` persists and turns later matches into
  misses. Rebuild per call: `new RegExp(re.source, re.flags)`.
- **Turkish needs explicit case folding.** `/i/ui` does not match `İ`, and `toLowerCase()`
  turns it into `i + U+0307`.
- **Diacritic folding is a per-locale decision, and it goes both ways.** `ro`'s `fold()`
  **must** normalise the legacy Turkish cedilla `ş/ţ` to comma-below `ș/ț`, because core
  mixes them. `sk`'s and `sl`'s `fold()` must **not** strip diacritics, because three of
  their distinctions ride on the acute alone (`ti`/`tí`, `vyber`/`výber`, `uprav`/`úprav`).
- **A missing `i` flag under-counts and looks like a real finding.** Any measurement that
  comes out at exactly zero deserves a second look before it goes into `registerEvidence`.
- **A HOMOGLYPH is invisible to every gate and to the eye, in both directions.** A Cyrillic
  `о` (U+043E) inside a Latin word, or a Latin `a` (U+0061) inside a Cyrillic one, renders
  identically to the right letter while breaking search, sorting and speech. The value is not
  empty, not identical to English, not wrong-arity, and reads as finished work. `npm run
  l10n:script` now checks this **for every locale including Latin-script ones**, which had no
  coverage at all; it found one defect in `bs` (`proširenо`) and one in `mk` (`првa`). Two
  things make the check usable, and both were wrong on the first cut:
  - **A hyphen is a morpheme boundary and must break the run.** Macedonian, Serbian, Bosnian
    and Albanian all attach case endings to a Latin acronym across one — `API-клуч`,
    `webhook-a`, `UUID-je` — which is correct morphology. Testing whole words reported 105
    hits on `mk` and 19 on `uk`, essentially all of it that construction.
  - **Check core before believing the rest.** `mk` writes `сè` with a *Latin* `è` and core
    `mk` does the same in 7 values with zero of the Cyrillic U+0450, so those 29 hits are the
    prevailing convention. Record such a run in `homoglyphAllow`, never suppress it in the
    script, and only ever with that corroboration.
  Detectors get this right because `fold()` lowercases; a throwaway probe written alongside
  one easily does not. This also applies to a *casing* measurement, where it reads as a
  contradiction and is not: match case-insensitively, then test the matched text's own case.
- **A word-token regex must know the target's word-INTERNAL punctuation.** Catalan writes
  its geminate l with U+00B7 MIDDLE DOT (`col·lecció`, `Cancel·la`), and `spell.js` had it
  outside the token class, so every such word split into junk halves that were reported as
  misspellings — and `instal·la` ended in `·la` and read as the article in an elision sweep.
  **An orthography can defeat a checker silently**, and the tell is a cluster of implausible
  short "words" that all share a stem with a real one.
- **THE LEFT GUARD MAY NEED A HYPHEN, AND `\p{L}` ALONE WILL NOT TELL YOU.**
  `(?<!\p{L})…(?!\p{L})` is correct for a language that writes inflection inside the word.
  Albanian attaches definite and case endings to acronyms **after a hyphen** (`UUID-je`,
  `Token-i`, `PHP-ja`), so `(?<!\p{L})je` matches an *inflectional ending* and scores it as
  the 2sg copula; `detectors/sq.js` guards with `(?<![\p{L}-])`. Two refinements: the right
  guard must stay `(?![\p{L}])`, since an ending attaches after the hyphen and never before
  it; and do **not** extend the guard to the apostrophe, because Albanian contracts on both
  sides of the register line (`t'ju` formal, `s'ke` informal). Where a language attaches
  acronyms to a *following* noun with a hyphen (`mk` `ВИ-агент`), the hyphen goes in the
  **left** guard only. The general rule: ask **where the target puts a morpheme boundary**,
  not just what counts as a letter — Catalan needed the interpunct *inside* the token class,
  Albanian needs the hyphen *outside* it, the same question answered opposite ways.
- **Stripping placeholders inserts whitespace, so whitespace checks must run on the raw
  value.** Replacing `{count}` with a space is right for an English-leftover scan (otherwise
  `{schema}` fires on every value carrying it), and it makes a doubled-space check report
  ~195 defects that are entirely its own artefact. Keep both forms of the corpus and point
  each check at the right one.

### 8.4 Wrong-sense harvests — the confirmed offenders

Every one of these passes all automated checks. Read the call site.

| Key | Looks like | Actually is | Where |
| --- | --- | --- | --- |
| `Right` | text alignment (`Desno`, `Rechts`) | an **RBAC permission** | `EditOrganisation.vue`, "Special Rights" table `<th>` |
| `Bucket` | an S3 bucket, a basket, a bouquet | a **histogram bin** | `QualityIndex.vue` fallback table |
| `View` | the noun (`Ogled`, `Visualització`) | an **action button** | `OrganisationsIndex.vue`, `SourcesIndex.vue` row actions |
| `Search` | core's verb (`Poišči`, `Найти`) | a **field/tab label** | `ObjectsList.vue`, `SearchSideBar.vue` |
| `Subject` | a mail subject, a school subject | the **GDPR data subject** | `AvgIndex.vue` column |
| `People` | humans in the abstract | the **`PERSON` entity type**, so *persons* | `EntitiesTab.vue` |
| `Label` vs `Labels` | the same word | a **facet range caption** vs **file tags** — often different words | `EditSchemaProperty.vue` vs `UploadFiles.vue` |
| `Test` | a cognate noun | a **button**, so the verb | `WebhooksIndex.vue` |
| `Revoke` | undo (`Cofnij`), reject (`Avslå`) | **revoking an API token** | `TokensSection.vue` |
| `Interval` | fine | a **date-facet granularity** — collides with `Bucket` if you translate `Bucket` as "interval" | `EditSchemaProperty.vue` |
| `Handler` | an event/callback handler | **a person** — the DSAR case handler, beside `Type`/`Status`/`Deadline` | `AvgIndex.vue` |
| `Apply` | fine | core `ga` has *submit a job application*; the key is a button | `PermissionMatrix.vue` |
| `Quota` | fine | core's value may be storage-specific and far too long | |
| `Slug` | a cognate | core `lt` and `sl` both translate it | |
| `Display Name` | fine | core `ca` has `Nom d'usuari` = *username* | |
| `Documentation` / `Avatar` | fine | core `et` has a gloss unfit for a label | |
| `Refresh` | fine | core `tr` has `Yenlle`, a typo. **Do not take typos** | |
| `Mappings` | fine | openconnector `hr` has a non-standard transliteration | |

**Sibling apps are not automatically right**, and a whole catalogue can be the wrong
language (§6.6).

**ASK WHAT LETTERS THE TARGET DOES NOT HAVE.** Where the script omits a letter its likeliest
contaminant uses, that gap is a free and exact wrong-language detector — and unlike §6.6's
byte-identity guard it works on the **committed bundle**, which is where the guard cannot
help. Belarusian has no `и`, `щ` or `ъ`: one grep found two committed Russian values in the
pre-existing half *and* established that openbuild's whole `be.json` is a separate Russian
translation, which the guard misses precisely because it is not byte-identical to their `ru`.
`uk` (no `ы ъ э`), `sr` and `mk` (no `й ы щ ъ`) all have such a gap. Run it before reading
values one at a time; where it exists it is the cheapest check in the pass.

### 8.5 Collisions the target language creates

Check that a coinage does not collide with a term the bundle already uses. Confirmed:

- `lt` **entity** → `esybė`, not `subjektas`, because `duomenų subjektas` is the GDPR *data
  subject*; **redaction** → `užtemdymas`, not `redagavimas`, which is this app's *Edit*;
  **Reports** → `Ataskaitos`, distinct from `Pranešimai` (*Notifications*).
- `ro`/`sk`/`sl` **Bucket** → `Segment`/`Pásmo`/`Razred`, never "interval", because
  `Interval` is its own key.
- `sl` **Revoke** → `Odvzemi`, not core's `Prekliči`, which this bundle already uses for
  **Cancel** on the same screen.
- `bg` **Connections** → `Свързвания`, because `Връзки` is already **Relations**; and
  **Subject** spelled out as `Субект на данните`, because bare `субект` was already shipped
  for *entity* — the `lt` fix of coining a different word for *entity* was not available.

Three rules:

- **A collision on the app's OWN primary noun is the worst case, and the recipe is fixed.**
  Core decides which sense cannot move; the bundle usually already contains the answer for
  the other sense (§3.5, and it beats coining a word); and because it propagates through
  scores of keys it is a terminology decision, so **ask the owner** (§6.4 step 2) and record
  the answer and whose it was in `lexiconNote`.
- **Some collisions are unavoidable and should be recorded rather than worked around.** `bg`
  renders both **Cancel** and **Denial** `Отказ`, correct in each case and core's own word
  for Cancel in ten catalogues; they never share a screen. Forcing an artificial distinction
  would make one of the two wrong. But **a collision the user can see on one screen is a
  defect however defensible each word is** — grep the `tabs:` arrays and the paired empty
  states (§6.9).
- Watch for locale-specific renderings of acronyms the bundle has already fixed: **AI** is
  `MI` in Latvian and `UI` in Slovenian, but product names keep the English (`Fireworks AI`,
  `OpenAI`). **GDPR** is `BDAR` in Lithuanian, `VDAR` in Latvian, `RGPD` in Romanian and
  Catalan.

### 8.6 Dutch source terms

`Bewaartermijn`, `Rechtsgrond`, `Verantwoording`, `verwerkingsactiviteit`, `Inzage`,
`Vergetelheid`, `Portabiliteit`, `AVG / Verwerkingsregister`, `verantwoordingsdocument` all
get rendered into the target language's own GDPR terminology. Prefer the wording of the
**official local translation of the regulation** over a literal rendering — that is where
`ispitanik` (hr) and `duomenų subjektas` / `tvarkymo veikla` (lt) came from.
`Autoriteit Persoonsgegevens` stays a proper name.

### 8.7 Permission-matrix headers are nouns, not buttons

`PermissionMatrix.vue` defines `actions: ['read','create','update','delete','manage']` and
renders each through `t(app, action)` as a **`<th>`**. They are permission names, so they
need **verbal nouns**, not imperatives. Both the `hr` and `lt` passes wrote imperatives
first and had to correct them. Same shape for the paired badge `set` / `missing` in
`AvgIndex.vue`.

### 8.8 Dynamic keys

Keys reached through a variable are invisible to static extraction: `t(app, action)`
(PermissionMatrix), `t(app, step.status)` (ApprovalStepList), `t(app, preset.label)`
(DashboardIndex), and every `menu[].label` from `src/manifest.json`. They live in
`DYNAMIC_KEYS` / `collectDynamicKeys` in `lib.js` — **keep that registry current or
`clean:l10n` will delete live keys from all 37 bundles.** `collectDynamicKeys` is
deliberately narrow; it once harvested `observability.metrics[].name`, making Prometheus
identifiers look like translation keys.

The lowercase `read`/`create`/`update`/`delete`/`manage` are **distinct keys** from the
capitalised `Read`/`Create`/… — the lowercase set are the matrix columns.

### 8.9 Process traps

- **The worklist is `absent ∪ identical`, every round.** Regenerating with `!(k in loc)`
  finds absent keys only and silently skips remaining placeholders. This happened mid-`ca`:
  26 were missed and only the status count caught it.
- **Do not copy a script out and edit it.** A copied `harvest.js` still had `['hr','hr_HR']`
  hardcoded, and the `lt` harvest returned 69 **Croatian** values matched against
  Lithuanian's key set — with a plausible hit count, so nothing looked wrong until the values
  were read. Every committed script takes the locale as an argument and errors without one.
- **A verification script written for one locale encodes that locale's assumptions.**
  Generalising `selfcheck`/`runtime-check` from `hr`/`lt` to all 21 exposed three wrong
  assertions, all from assuming every locale drops `{plural}`. Run a new assertion across
  **every** finished locale before trusting it.
- **Doubled-whitespace checks must be horizontal-only** (`[ \t]{2,}`). `\s\s` also matches
  the `\n\n` paragraph breaks the English source carries.
- **`clean:l10n` needs review before every run.** It removes keys from all 37 bundles, and
  some candidates are live UI prose nobody has wrapped in `t()` yet. The npm alias is
  deliberately the dry run. Cross-check against `find:unwrapped`, then remove by hand.
- **`find:unwrapped` is deliberately high-recall** (~1500 candidates). Audit by hand; do not
  "fix" it by tightening the heuristic until real strings are missed.
- **Check a drafted value's meaning against the file's established term, not just its
  plausibility.** The `lt` draft used `negrįžtamai` ("irreversibly") for *soft-deleted* — the
  exact opposite — where the file already had `minkštai pašalinti`.

### 8.10 Measure the locale's house conventions, not just its register

Register, buttons and plurals all have a step in §5. **Orthographic and typographic house
conventions do not, and they touch more values than register does.** Measure them from the
pre-existing half of the bundle before the first batch, and record the counts in
`locales/<loc>.json` — the same standard of evidence. Mid-sentence capitalisation of domain
terms is the big one: in `sr` it decides a third of all values, and no gate can see a
wrongly-cased value because it is otherwise a perfectly good translation.

**How to measure: `npm run l10n:casing -- <loc>`.** Do not hand-roll it again. The three ways
the hand measurement misled — a missing `i` flag hiding the very occurrences being counted, a
`(?<=.)` guard that excludes the value's first word but not a later sentence's, and brackets /
leading emoji / all-caps each licensing a capital — are all encoded, along with the
conditioning on the English key that separates prose from Title-Cased headings. It prints the
bundle against the sibling-frontend and core baselines, and every term with its own up:down
split. `--mine` restricts it to the values this working tree changed, which is the split-at-HEAD
check for your own drift.

A one-sided split is a convention to follow; a 1-of-34 outlier is a slip worth normalising
while you are there. **Aggregate by word class before deciding**, because the report keys terms
by surface form: `bs` looked like four separate 5:0-to-20:0 terms and was one lemma at 29:0.
**And a term that is one-sided in neither direction has no convention to follow** — `bs`
capitalised `Izvor` in 5 values and lowercased it in 4, against five other nouns at zero
counter-examples. Resolve that one DOWN, to the ordinary-noun default: the capitalised set is
an explicit exception list and a term the bundle never settled does not join it.

**Five outcomes have turned up, and they need opposite handling** — so run the *conditioned*
measurement even when the unconditioned one already looks one-sided, because that is the
only thing that tells the second outcome from the third:

| Outcome | Locales | Tell |
| --- | --- | --- |
| a **list** of capitalised terms | `sr` `rm` `mt` | one-sided per term, and the lists disagree between locales — `rm` capitalises `Schema` but lowercases `object` 0:63; `mt` capitalises `Reġistru` but lowercases `skema`, the app's two paired core concepts |
| **mirror the source** | `ga` | every term reads MIXED per term, which looks like carelessness. Condition on whether the **English key** is Title Case and it resolves: 76:1 capitalised under title-cased keys, 0:193 under prose keys |
| **flat lowercase** | `is` `mk` | one-sided lowercase per term, and conditioning on the key's casing changes *nothing* |
| **forced by orthography** | `lb` | Luxembourgish capitalises every noun, so there is nothing to follow. Measure anyway — it costs one command and it is what proves it |
| flat uppercase | — | unobserved |

Three further rules, each of which caught something no gate can:

- **Condition on the English key's own casing, or the measurement will invent work.** On
  `sq` the naive scan reported domain terms capitalised 25–35% against a family rate near
  zero — a tidy ~110-value defect class that **does not exist**: restricted to prose keys
  (English key ≥6 words, not Title Case) it is 0 against 177, every hit a Title-Cased
  heading correctly mirroring its source. Note the direction of the error, because "my
  locale capitalises where the family does not" is exactly the shape a pass will act on.
  **The cheap tell: a defect class that is large, uniform and concentrated in short values
  is measuring the source, not the translation.**
- **But it can come back real** — `mk` still ran 41:146 under prose keys against a family
  ~2:750. Three things separate that from `sq`'s phantom: it does not evaporate under the
  restriction; the **family disagrees** rather than the bundle mirroring its source; and the
  bundle is **internally inconsistent**, so there is no convention to follow. §8.11 decides
  the uniform terms: a uniform lemma is one decision copied, not a rule.
- **Measure the PRE-EXISTING half separately from the half you just wrote, per term.** This
  catches your own drift, which whole-bundle counts hide because the pre-existing majority
  outvotes the new values. On `mt`, HEAD capitalised `Fajl` 43:0 while the new values
  lowercased it 24:4 — a convention broken in 24 places, invisible to every gate.

**Apply the same split to DECLENSION, not only to casing.** A borrowed noun may be treated
as indeclinable by the bundle while the language would ordinarily inflect it — `is` had
`skema` bare 25 times at HEAD against one declined form, and the new values introduced
`skemað`: grammatically defensible and inconsistent with the file, which is what §3.5
settles against. Two practical notes: tally **per surface form** rather than per lemma so
the split is visible at all, and use `\p{L}` not `\w` — `\w` is ASCII-only, so `skema\w*`
silently truncates `skemað` to `skema` and hides exactly the drift you are looking for.

**Review the word list before applying any casing fix**, and generate the fix from the
reviewed list rather than from an open-ended pattern. Three ways the measurement misleads,
all met on `mk` in one session, splitting 132 raw hits into 118 real defects and 14 licensed
capitals:

1. **Case-insensitivity** — a stem alternation written without the `i` flag matches only the
   lowercase form, so every capitalised occurrence, *the entire thing being measured*, is
   invisible and every term reports a clean 0-up. This gap hid the finding entirely.
2. **`(?<=.)` does not exclude a LATER sentence's first word**, only the value's. Three
   first-cut hits were a second sentence in a multi-sentence description, and each would have
   been "corrected" into an error. Add a lookbehind for a sentence terminator plus space.
3. **An opening parenthesis, a leading emoji and deliberate all-caps each license a capital**
   the same way a sentence start does — `(Опционално)` mirroring `(Optional)`, emoji-initial
   headings, `НЕМА ДЕЈСТВО` mirroring source caps.

The same applies to the conventions collected in `docs/l10n-ui-translation.md`: ellipsis
spacing (`nb` and `sl` put a space before, `ru` and `bg` do not), dash choice, whether `%`
takes a space, quote glyphs (`da` opens with `”`, `ru` uses guillemets), and weaker
domain-term capitalisation in `da` `sv` `pl`.

### 8.11 OBLIGATORY SANDHI — the one mechanical check that pays

**§6.9 says not to build mechanical morphology checks. This is the exception**, and the
difference is that this is not agreement or case governance but an **orthographic rule with
a deterministic trigger**, so precision is high enough to act on. On `lb` the Eifeler Regel
— word-final `-n` deletes before any consonant but `n d t z h` — accounted for 60 of 77
corrections, and it is obligatory, fires several times per sentence, and no gate can see it.

Four things made it tractable, and all four generalise to any language with obligatory
sandhi (French elision and liaison, Irish initial mutation, Welsh, Italian `lo`/`il`):

1. **Measure the bundle's own practice — but aggregate by WORD CLASS, not only per lemma.**
   A per-lemma check is necessary and **not sufficient**, and believing otherwise cost `lb` a
   whole round. A lemma occurring in only one environment carries no information, so a pile
   of them reads as a convention: 26 values were excused on a "16:0, no counter-example"
   count that was really one copy-pasted phrase repeated thirteen times. By word class the
   family did both, 118 to 108. **Run both cuts** — per lemma to find the internally
   inconsistent ones, per word class to find the ones that are uniformly wrong. **A uniform
   lemma is evidence of one decision, possibly copied, not of a rule.** Be strict about what
   counts as "no counter-example": search for the *construction*, not the word, and say which
   environments you checked.
2. **Encode the exemption classes, or it is all noise.** On `lb`, stem-final `-n` is not
   inflectional, so `-ioun`/`-ion` nouns keep it always (25 of the first 95 raw hits), as do
   non-integrated loans (`Token`, `JSON`) and monosyllabic `-nn` stems. A later run needed
   `-ern` too, after the auto-fixer turned `extern` into the non-word `exter`.
3. **Check BOTH directions.** The rule was broken both ways — 75 failures to delete and 8
   wrong deletions before a vowel. A check written for one direction reports a clean bill of
   health on the other.
4. **Split at HEAD and re-run it on your own half.** The newly written half carried 44
   further violations, more than half the count in the pre-existing half.

Where the bundle and the strict rule genuinely disagree and **no** environment in the corpus
breaks the tie, leave it and record it — on `lb` that was 2 values, not the 26 first claimed.

**ASK THE QUESTION EVERY TIME, BUT DO NOT EXPECT A SANDHI RULE TO BE WAITING.** On `mk` the
answer was no, and the two reasons generalise. Macedonian obligatorily doubles a definite
direct object with an accusative clitic, which is obligatory, fires constantly, invisible to
every gate — and looks exactly like the Eifeler Regel while not being the same shape,
because **the trigger is semantic (definiteness) rather than orthographic**, and because
**the clitic's position depends on clause type** (after the verb in an imperative, before it
elsewhere). Measured yield: **0 of 5**, in line with §6.9's general rule rather than this
section's exception. What paid on `mk` instead was capitalisation (§8.10). So the durable
form of this section is a *question*: ask what rule this language has that the three reports
cannot check, and be willing for the answer to be a different rule each time — or none.

---

## 9. Remaining work

### 9.1 CLOSING TASK — delete `FINISHED_DEFAULT` once all 36 are done

`FINISHED_DEFAULT` in `tests/l10n/check-l10n-parity.js` is **scaffolding for the migration,
not the end state.** It exists only so the gate can be fatal for finished locales while the
rest are in progress. The moment the last locale reaches parity:

- Delete `FINISHED_DEFAULT`, the `FINISHED` set, and the `L10N_FINISHED_LOCALES` override.
- Make missing-key parity **fatal for every locale, unconditionally** — the way empty values
  and wrong arity already are.
- Delete the backlog-reporting branch. With nothing in progress there is no backlog, and
  leaving it in invites someone to re-add an "in progress" escape hatch.
- Update `CLAUDE.md`, `docs/l10n-ui-translation.md`, `scripts/l10n/README.md` and §2 here.

Rationale worth preserving: the env override and the per-locale set are exactly the knobs
someone reaches for to turn a red build green. Once unnecessary they are a liability.

### 9.2 Review the pre-rule locales — `cs` done, 15 to go

The remaining set is `da de el es fi fr hu it nb nl pl pt ru sv uk`. Per locale:
`npm run l10n:status -- <loc>` lists the unjustified identical values;
`npm run test:l10n:parity -- --strict-identical` audits them. For each, decide genuine
cognate (record the reason) or filler (translate it). Then write `locales/<loc>.json` and it
becomes enforced from that moment — no code change needed, the gate keys on the file
existing. Expect a high legitimate rate in some (`nl` renders `Bewaartermijn` unchanged —
Dutch words in a Dutch bundle) and the opposite in others; this is the audit that turned up
46 placeholders in `tr`.

The register verdicts for these 16 were already recorded, so step 2 is done — but
**re-measure rather than trusting the record.** A detector is required for `selfcheck`
anyway, so the measurement is nearly free once you are there, and on `cs` it turned two
pre-tooling assumptions into 828-vs-0 and 27-vs-0.

**What to budget for.** The re-audit handoff predicted these would be as bad as `is` or
worse. They are not — the defect rate tracks **how healthy the locale is upstream, not how
long it went un-audited**:

| | `is` | `cs` | `sk` | `ca` | `lb` | `sq` | `mk` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| values audited | 1052 | 2052 | 2052 | 2052 | 1011 | 1018 | 1053 |
| defects | 235 (**22%**) | 113 (**5.5%**) | 57 (**2.8%**) | 128 (**6.2%**) | 77 (**7.6%**) | 160 (**15.7%**) | 114 (**10.8%**) |
| garbled / foreign stems | 14 | **0** | 1 | 2 | 2 | 2 | 1 |
| agreement failures | 11 | **0** | **0** | 1 | 2 | 14 | 4 |
| wrong case | 19 | 6 | **0** | — | **0** | **0** | — |
| wrong plural arrays | — | **0** | **0** | **0** | **0** | **0** | **0** |
| dominant class | compounds, wrong senses | terminology drift | **one term (37 of 57)** | on-screen collisions plus a long tail | **one orthographic rule (60 of 77)** | one stem (39) plus the button convention (36) | **one orthographic rule (105 of 114)** |

Four things that table is trying to say:

- **Budget for terminology counting, not grammar repair**, on any actively maintained
  locale. `cs` and `sk` are both healthy West Slavic bundles and for the same reason.
- **A locale can be healthy in grammar and healthy in vocabulary and still be a tenth
  wrong, if it is inconsistent about one mechanical rule.** `lb`, `sq` and `mk` are all this
  shape. **Measure the rule, then count violations** — do not read values hoping to notice.
  So where all three reports come back thin, do not conclude the locale is healthy: ask what
  rule the language has that the reports are structurally unable to check (§8.11).
- **The defects usually concentrate, but not always.** `sk` was 37-of-57 in a single term, so
  the pass was one sweep. `ca`'s biggest class was 35 keys with eleven further classes
  carrying 2–11 each, which is slower per defect and means **you cannot stop when the term
  count goes quiet.**
- **A `corrections` count of 0 means "unverified", not "clean" — and not "a quarter of the
  file is waiting" either.** `sk` had a measured register, a detector, a reviewed cognate set
  and an empty `corrections`, and the audit still found 57 real defects including two
  semantic reversals invisible to every gate. Read a 0 as "nobody looked".

---

## 10. Source-side defects still open

These are **source** fixes, not translation work. Each one costs 37 bundle entries or
renders wrongly in every locale.

- **`test:l10n` may be RED without anyone touching l10n.** A `development` merge that
  de-Dutchified the AVG source strings and added flow strings left 17 keys used in `src/`
  but missing from `en.js`. Most were English replacements for still-present Dutch-keyed
  strings, so **the fix is a rename, not an add** (§6.15) — that carries all 37 bundles at
  once, so every finished locale's translation survives and parity never breaks. Do the
  renames first, then `test:l10n:write` for the genuinely new keys, then translate those
  across the finished set. Its own commit, per §3.10. Re-run `npm run test:l10n` to see
  whether this is still open.
- **`{plural}` hardcodes English morphology** — 13 call sites in
  `src/sidebars/register/RegisterSideBar.vue`, `RegistersSideBar.vue`,
  `src/views/register/RegistersIndex.vue`. Should be `n()` with real plural keys. **Highest
  value fix left:** 5 keys × 36 locales of deliberate approximation, and `hr`/`lt`/`sl`
  showed it cannot be done correctly at all in a three- or four-form language. **The owner
  has taken this one: it will be fixed after all 36 locales are done** (2026-08-19), so a
  locale pass should note its chosen shape in `pluralHackNote` and otherwise ignore the
  issue entirely — §7.4.
- **Dual-role keys.** `Create`/`Read`/`Update`/`Delete` render as both `<th>` headers and
  buttons, which is what forced `ro` onto verbal nouns (§7.3). Splitting them would let the
  pure-button keys take imperatives.
- **Duplicate keys for one concept**: `Url` vs `URL`, `Data Quality` vs `Data quality`,
  `{days} day(s) left` vs `{days} day(s) remaining`, `Test Connection` vs `Test connection`,
  `Add Endpoint` vs `Add endpoint`, `Loading...` vs `Loading…`, `Exclusive Maximum` vs
  `Exclusive maximum`. The locales work around these by translating both identically; that
  is a workaround, not the fix.
- **Unwrapped literals** remain — `npm run check:l10n` counts them and `npm run
  find:unwrapped` lists candidates. Concentrated in `MassValidateModal`, the workflow
  components, `ImportRegister` and `ValidateSchema`. Many already have translations in all
  37 bundles that never render. A handful of further `t()` calls are unanalyzable (dynamic
  args) and are reported separately.
- **`pages[].title` is never translated.** `CnPageRenderer` forwards it as a raw prop; only
  `menu[].label` goes through CnAppNav's `translate`. So `Application`, `Webhook logs`,
  `Entity`, `My account`, `Report` render English in every locale. Fix belongs in
  nextcloud-vue.
- **`RegisterSchemaCard.vue:713`** wraps a runtime-built template string in `t()`.

---

## 11. openconnector reciprocal work

`scripts/l10n/lib.js` here is the **origin**; openconnector vendors a copy, because the two
apps ship separate npm packages. The only intended divergence is `DYNAMIC_KEYS`, which is
app-specific data.

As of 2026-08-14 openconnector is behind on four counts:

- the file is still at the old **`scripts/lib/l10n.js`** path, which no longer exists here;
- it still stores plurals under the bare singular (§7.1);
- its `CLAUDE.md` still states the incorrect "the singular is the catalogue key" rule;
- its `check-l10n-parity.js` predates the arity, identical-value, finished-set and
  cognate-justification gates, and its whitelist lacks `spellAllow` (§4.4) — port the
  whitelist with the rest, or a vendored copy will keep silently dropping the field.

The whole of `scripts/l10n/` is worth porting, not just the library — openconnector has the
same 37-bundle problem and none of the tooling.

**A detector's family scan finds defects in the sibling apps' bundles, and they are worth
reporting there.** The `sq` detector found the only two informal values in a 3980-value
corpus, both in openconnector, against a measured 218:1 formal core. Re-run
`node scripts/l10n/detectors/<loc>.js` from this repo after any fix.
