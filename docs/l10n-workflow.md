# The frontend l10n workflow — complete runbook

Everything needed to take one `l10n/<loc>.js` from partial to complete, correctly, and to
hand the work to someone else mid-stream. This is the **operational** document: order of
operations, which command to run, and what to do when a gate refuses. Two companions:

| Document | Covers | When to read it |
| --- | --- | --- |
| **this file** | the whole pass, in order; every refusal and what it means; the traps catalogue | start here, every time |
| `scripts/l10n/README.md` | the tooling layout, and what each script refuses | when a script surprises you |
| `docs/l10n-ui-translation.md` | the per-locale **linguistic** reference — register verdicts, button conventions, plural boundaries, homograph traps per language | before translating a specific locale |

`CLAUDE.md` holds the short version of the hard rules. It also carries a snapshot of the
counts for orientation, under an explicit "re-measure before trusting any number here" —
treat it that way, and see §2 for how to measure.

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
language outright (see §8.6).

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

The last one is the fastest smell test there is — see the invariant below.

### 2.2 The invariants

These are the rules the counts have to satisfy. Each is enforced by a gate; knowing them
is how you tell "in progress" from "broken".

1. **Every finished locale has exactly the same key count as `en.js`.** Not approximately.
   `test:l10n:parity` fails a finished locale that is one key short. This is the parity
   guarantee, and §3.1 is why you never work around it.

2. **Therefore every finished locale has the same *line* count as `en.js`, exactly.**
   `serializeJs` emits one line per key plus a fixed wrapper, so `wc -l l10n/*.js` is a
   one-second check on the whole set: the finished locales are all identical, and anything
   in progress is visibly shorter. Verified across all 25 finished locales. A finished
   locale whose line count differs from `en.js` means something is wrong even if the key
   count matches — a duplicated key, or a hand edit.

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

The high-confidence group is **done**, and so are `rm`, `ga`, `mt` and `is`, the first four
of the low-resource ones. Five remain: `lb sq mk be bs`, **in that order** — the owner
has confirmed the order, so there is no need to re-ask per locale as long as it is kept.

**Check whether core can decide the register at all before planning a pass.** Two of
the five remaining locales cannot be measured from core, which §5 step 2 assumes:

| Locale | Core coverage | Consequence |
| --- | --- | --- |
| `bs` | 1 catalogue, 55 values | not evidence of anything; use the §6.4 fallback |
| `lb` | 1 catalogue, 72 values | same, and openbuild's `lb` is German (§6.6) |
| `mk` `be` | 24 / 14 catalogues | core is usable |
| `sq` | 12 harvest sources, core usable | measure it |

(`rm` and `mt` both had **zero** catalogues. `rm` is the worked example of the fallback,
§6.4; `mt` extended it by measuring the sibling apps' **frontend** `mt.js` alongside the
app's own values, which tripled the corpus to 3422 values — worth copying for `bs` and
`lb`, whose single core catalogue is no better than nothing. `ga` had 33 and produced the
most one-sided verdict in the set, §7.2.)

Durable per-locale notes for the ones not yet done (facts about the language or the
sources, not counts — these will not go stale):

| Locale | Note |
| --- | --- |
| `mk` `be` | Non-Latin. `npm run l10n:script` replaces the English-leftover sweep in §5 step 8 |
| `mk` | Confirmed to disagree with the library on the plural *boundary* (at 11 and 111 only, the library selecting the SINGULAR where Macedonian takes the plural). Not fixable by reordering; needs `pluralBoundary: "library"` and an explicit note of which counts stay wrong (§6.7). `is` had the same class of problem in the opposite direction and is now done |
| `lb` | openbuild ships **German** under `lb.json`; harvest drops it automatically (§6.6) |
| `bs` `sq` | Very few harvest sources (7 and 12) — expect to translate almost everything by hand. Even `ga`, with 40 sources, only got a 7% hit rate, and `mt` 5.2% from 7 |
| `bs` | openbuild's Croatian catalogue also ships under `bs.json`; dropped automatically |
| `bs` `mk` | Same openbuild Croatian catalogue again. It has now been observed under **seven** names (`bs cs hr mk sk sl sr`), and in `sr` the contamination had already reached the *committed* bundle as `Revizijski trag #{id}` — so for these two, expect the defect **inside** the file and not only in the harvest sources (§8.4) |

**The closing task**, once the last locale lands, is §9.1. It is not optional cleanup.

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
those notes in a free-form field (`lexiconNote`) or the commit message. Attempted twice
(`Test` in hr, `Interval` in lt); reverted both.

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
(core `lt` translates it `Trumpinys`, `sl` renders it `Oznaka`), `Ollama URL` (29 locales
translate it).

The reverse also holds and is how the `OpenCorporates` label was unwrapped: a single
distinct value across all 37, for a string that is a bare product name, is evidence it was
never translatable prose to begin with (§6.15).

### 3.4 Follow Nextcloud core per language for register — measure it, never assume

Ten consecutive locales came out differently. Carrying an answer over from the previous
locale passes every automated check while being wrong in every string that addresses the
user. §5 step 2 is how you measure it; §7.2 records the verdicts.

**And a locale may have no register choice at all.** `ga` is the first: Irish has no T-V
distinction, `sibh` being strictly plural rather than a polite singular. Its recorded
`"register": "informal"` therefore names the *only* address form available, not a
preference — and its function is to set the gate's polarity so `patchcheck` refuses 2pl
address, which in a single-user UI is always a defect. Read a verdict for what it is
before repeating it: "informal" means something different for `ga` than for `nl`.

**Three consecutive locales then came out "informal" for three genuinely different
reasons, and that is the point.** The label is the same and the situation is not:

| Locale | Why it reads "informal" | What the gate is catching |
| --- | --- | --- |
| `ga` | Irish never had a T-V distinction. No choice exists | 2pl address, which is only ever a defect here |
| `mt` | Maltese **has** one and it is current (`intom`, `Is-Sinjur`); it is simply unused | genuine deference — a live option the project declines |
| `is` | Icelandic **had** one (`þér` + 2pl verb, possessive `yðar`) and **abandoned** it in the 20th century | archaic deference *and* plain wrong number (`þið`, `ykkar`), which are different mistakes |

So there are three distinct states — never had one, has one unused, had one and dropped
it — and they differ in what a slip would look like. `is` is the interesting case: because
its V-forms are obsolete rather than absent, the *likelier* error is not `yðar` at all but
the plain modern plural `þið`/`ykkar`, since a translator importing a politeness plural
from de/fr/nl reaches for the form that is actually current. Its detector gates on both
and says so. **Do not copy a verdict without its reason.**

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
**not** interchangeable — §6.7. Any other field is free-form documentation and is
ignored — `registerEvidence`, `buttons`, `orthographyNote`, `lexiconNote`, `pluralHackNote`
are all in use. Note `loadLocaleConfig` whitelists the keys it reads, so a **new**
functional field must be added there or it is silently dropped (this bit once, with
`pluralOrder`, which is why `pluralBoundary` was added to the whitelist in the same
commit that started reading it).

**`scripts/l10n/detectors/<loc>.js`** — the register detector. The gates call exactly two
things, so those are the required exports: **`score(s) -> {f, i}`** and
**`runControls() -> {fail, total}`**. Also export `fold` and `CONTROLS` by convention, and
**`UNDETECTABLE`** — a list of `[example, why]` pairs for informal styling the detector
*cannot* see. That last one is documentation rather than interface (`ca.js` omits it), but
write it: it is the honest record of the detector's blind spots, and without it the next
reader assumes a clean scan means clean data. Running the file directly executes its own
controls **and** scans core:

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
there are **four** patterns (§7.3). Measure it from core's own short labels: resolve ~30
bare action keys (`Save`, `Delete`, `Add`, `Cancel`, …) against core's catalogues and
classify the results. Record the counts.

**4. Write `detectors/$LOC.js`** from **closed word lists, never suffix patterns**, with
must-fire / must-not-fire controls that include that language's homograph traps (§8.1–8.3).
Every control should be a real value from this bundle or from core where possible. Aim for
40+ controls; the recent passes run 46–63.

**5. Read the locale's own existing values, and GRAMMATICALLY AUDIT THEM.** All of them — an
unfinished bundle carries roughly half the key set already, and on `is` **235 of those 1052
values were defective**. This is the single most under-budgeted step: plan for it to take as
long as a translation batch, and see §6.9 for the method and for the two ways the mechanical
checks mislead you. You are also looking for the
domain terms — audit trail, view, chunk, embedding, webhook, flow vs workflow, payload,
token, dashboard, hash, soft delete, `Delete` vs `Remove`, `Type`, `Filters` — and for the
locale's **typographic and orthographic conventions**: ellipsis spacing, dash choice, whether
`%` takes a space, how progressive states are phrased, and **whether domain terms are
capitalised mid-sentence**. Measure that last one per term rather than eyeballing it (§8.10);
in `sr` it decides a third of all values and no gate can check it. Also note anything that
looks *wrong* (§6.9).

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
  recorded cognate or a normalisation (`Url` → `URL`) — for `bg` the list came to exactly the
  14 cognates plus those two. **Latin runs inside translated values** should all be
  placeholders, product names, acronyms or literal example values; read them once and
  confirm. The `bg` sweep turned up 148 distinct runs and every one was `{count}`,
  `Nextcloud`, `HTTP`, `localhost`, `config.json` or similar. The script requires the locale
  as an argument and refuses a locale whose expected alphabet is not recorded in it, since
  `sr` also ships in Latin in the wild.

  **This check finds a defect class the Latin-script locales cannot have**, which is why it is
  a replacement rather than a nice-to-have. In `sr` it isolated the only two non-cognate
  Latin-only values out of 2052, and both were real:

  - `NO ACTION` → `NEMA RADNJE` — correct Serbian, written in the **wrong alphabet**. No
    other gate can see this: it is not empty, not identical to English, not a wrong plural,
    and it reads as a translation to anyone skimming the file.
  - `Audit trail #{id}` → `Revizijski trag #{id}` — wrong alphabet **and** Croatian **and**
    inconsistent with the bundle's own 24 other audit-trail values.

  So on a non-Latin locale, run the sweep **before** concluding the bundle's pre-existing
  half is sound. Both of those had survived every gate for the whole life of the file.
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
  `### <loc> traps` section, and the "N locales are complete" line.
- `scripts/l10n/README.md` — the enforced-locale count.
- **this file** — remove the locale from §2.3, and add to §6/§7/§8 if the pass taught a new
  refusal, boundary or trap. **Do not add counts here** (§2); the durable output of a pass
  is what it *learned*, not how many keys it moved.

**12. Regression-check every recorded locale, then commit.**

```bash
for l in tr ca et hr lt lv ro sk sl bg sr rm ga mt $LOC; do
  node scripts/l10n/selfcheck.js $l | tail -1
  node scripts/l10n/detectors/$l.js | grep controls
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

Core is genuinely inconclusive; that is a finding, not a failure. This happened once, with
`ro` (124 formal vs 66 informal). Procedure:

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
- **Core really ships nothing for this locale** → this was the case for `rm` and `mt`, and
  effectively for `bs` and `lb` (§2.3). Fall back to the bundle's own values, which is
  §6.4 step 1, and say so explicitly in `registerEvidence` — a verdict from the app's own
  file is weaker evidence than core and the record has to show which one it rests on.
  `rm` came out 81 formal against 0 informal over its 995 translated values, which is
  one-sided enough to settle without core. Note the detector's `main` block then cannot
  call `scanCoreRegister`; `detectors/rm.js` re-checks that core is still empty (rather
  than asserting it from a comment) and scans the bundle instead.

  **Widen the fallback corpus to the sibling apps' FRONTEND bundles.** `mt` did this and
  it tripled the evidence: 1015 values of its own became 3422 by adding
  `apps-custom/*/l10n/mt.js`, which is what turned a thin 26-vs-0 into a 128-vs-0. Do it
  for `bs` and `lb`, whose one core catalogue is no better than nothing. Two constraints,
  both load-bearing: include only the **`.js`** bundles, because the backend `.json` is a
  separate catalogue with a separate consumer (§1) — openregister's own `mt.json` contains
  an `int` that would otherwise leak into the measurement and be miscredited to the
  frontend — and exclude byte-identical mislabelled catalogues the same way `harvest.js`
  does (§6.6).

### 6.5 Deciding whether the 2sg imperative is detectable

Most locales must **exclude** the bare 2sg imperative from the detector. `sk` is the one
that counts it. Two independent tests, and it takes **both**:

1. Is the imperative the locale's own **label convention**? If yes, counting it flags every
   button in the app. (`ca`, `et`, `hr`, `sl`: yes → exclude.)
2. Is the imperative a **homograph of the 3sg present**? If yes, counting it flags ordinary
   third-person prose like "automatically creates". (`hr` `uredi`, `ro` `Creează`, `lv`
   `meklē`, `sl` across the whole `-iti` class: yes → exclude.)

`sk` fails both tests, so it counts them: labels are infinitives, and `ulož` ≠ `uloží`.
`sl` — Slovak's immediate neighbour — passes both, so it excludes them. **This is about the
data, not the language family.**

**`ga` is the first locale where both tests come out the same way**, which is worth
naming so the two are not assumed to be in tension. Irish labels buttons with the bare
imperative (test 1: yes), *and* for the whole `-áil` class the imperative is spelled
identically to the verbal noun (test 2: yes) — and the verbal noun is live in this
bundle's prose as the progressive, so `Ag sábháil...`, `Ag cóipeáil sonraí...`,
`Ag tástáil...` all carry the imperative form as their second word. Several stems are
ordinary nouns besides (`Scrios` = "destruction", `Dún` = "a fort"). The exclusion is
doubly forced, and it takes both tests to see that rather than one.

**`rm` is the exact mirror of `sk`, and shows the two tests are genuinely independent.**
Romansh also labels buttons with the infinitive, so test 1 comes out the same for both —
but test 2 goes the other way: for every `-ar` verb the 2sg imperative is spelled
identically to the 3sg present *and* to the feminine singular past participle. `stizza`
is "delete!", "it deletes" and "deleted-f.sg" at once, and the 3sg reading is live in
ordinary prose (`Quai stizza las endataziuns`, `Ferma mintga flux`, `Elavurescha ils
chunks` are all real values). Same button convention, opposite verdict. So do not infer
test 2 from test 1, and do not carry either answer across from a neighbour.

`rm` also loses a second paradigm, which is worth checking for elsewhere: **the 2sg
present of its regular verbs ends in `-as`, which is also the feminine plural of every
noun and adjective.** `controllas` is "you check" and the noun "checks"; `empruvas` is
"you try" and "attempts"; `tschernas` is "you choose" and the plural of the noun
`tscherna` — and in each pair the noun reading is the one that occurs in this bundle.
Detection there rests on the ten irregular verbs, whose 2sg ends in a bare `-s`. When a
locale's informal count comes out suspiciously low, check whether an inflectional
homograph has eaten the paradigm before concluding the prose is formal.

If the imperative is a 3sg homograph but you still need to catch it in *labels*, use a
position bound: string-initial plus a length cap (`ro.js` uses 40 characters).

**There is a third outcome: PARTIALLY detectable, split by conjugation class.** `bg` is the
first, and the split is worth looking for elsewhere because it is free precision:

- Bulgarian **и-conjugation** imperatives are unusable, and doubly so. `запази` is at once
  the 2sg imperative, the 3sg present (`може да запази`) *and* the 3sg **aorist**
  (`той запази`) — and the aorist reading is live in ordinary UI prose, which no other
  locale's homograph is. `Анализът завърши:` and `Изтриването завърши` are real values in
  this bundle spelled exactly like imperatives.
- Bulgarian **а-conjugation** imperatives are unambiguous, because the 3sg present of that
  class ends in `-а`/`-ва`: `опитай` vs `опитва`, `изпълнявай` vs `изпълнява`, `записвай` vs
  `записва`. Nothing else in the language is spelled that way.

So `detectors/bg.js` enumerates the `-й` class and records the `-и` class in `UNDETECTABLE`.
That is not a tidiness exercise: the two informal slips already shipped in the `bg` bundle
were *both* а-conjugation, so a whole-class exclusion would have found neither. When a
locale fails test 2, check whether it fails it for **all** of its verb classes before
excluding the whole paradigm.

**`is` is the second PARTIALLY-detectable locale, and there the split falls out of the
conjugation classes as a rule rather than a word list** — which is what makes it worth
copying. Icelandic forms a 2sg imperative by fusing the pronoun onto the verb (`nota` →
`notaðu`, "use!"), so the imperative *is* an address marker. Whether it is usable depends
entirely on how that verb builds its past:

- **Class 1** (`-a` verbs) take `-uðu` in the 3rd person plural past while the imperative
  is `-aðu`. `notaðu`/`notuðu`, `skoðaðu`/`skoðuðu`, `afritaðu`/`afrituðu`. **Distinct, so
  usable.** So are the strong verbs whose past differs by ablaut — `veldu`/`völdu`,
  `farðu`/`fóru`, `taktu`/`tóku`, `hafðu`/`höfðu`.
- **Class 2** (`-ja`/`-ta`/`-la` verbs, past in `-ti`/`-di`/`-ði`) build the 3pl past
  **identically** to the imperative: `settu` is both "enter!" and "they put", and likewise
  `sendu`, `smelltu`, `reyndu`, `breyttu`, `ýttu`, `skráðu`, `endurstilltu`. **Unusable.**

And unlike `ga`'s `-igí` — where the trap was real but happened not to occur — here the
collision is **attested in the corpus**, which is what settles it rather than merely
suggesting it: `komu` appears 4 times as a 3pl past ("Það komu of margar beiðnir" — "too
many requests came") and `völdu` twice as the weak adjective *selected* ("úr völdu
sniðmáti"), never as imperatives. `staðfestu` is trebly ambiguous, being also the oblique
of the noun `staðfesta` ("confirmation").

Note also that `is` is the **first locale where §6.5 test 1 comes out NO** — its labels are
infinitives (§7.3), so counting the imperative does *not* flag every button. That is why
the class-1 forms can be counted at all; in `ga`/`mt`/`sr` the label convention forbade it
regardless of homography. Run both tests; the answers are independent.

**One further trick worth reusing: a BIGRAM can rescue two individually-ambiguous tokens
at once.** Icelandic `þér` is both the 2sg dative ("þér er ekki heimilt" — perfectly
ordinary informal prose, 54 occurrences in core, all of them this) and the archaic polite
nominative. Neither reading is decidable from the word alone, and neither is `hafið`
("the ocean" / past participle of `hefja` / 2pl verb). But `þér hafið` can only be the
V-form, because a dative `þér` takes no finite 2pl verb and "the ocean" does not follow a
pronoun. `detectors/is.js` therefore counts bare `þér` as *informal* and matches the pair
as *formal*, in both orders since a question inverts it. Look for this wherever a locale
has a pronoun and a verb form that are each ambiguous but not jointly so — it recovers
recall that a per-token closed list has to throw away.

One caveat that comes with counting any imperative: it measures against **this bundle's**
label convention, not core's. Core `bg` uses `-й` imperatives as labels in 29 places while
this bundle uses verbal nouns throughout — so the detector is right for `patchcheck` on new
values and would report noise if pointed at core's labels. Say which one it is measuring in
`locales/<loc>.json`.

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
**which form index** a count selects. The library is what renders, always. But there are
**two kinds** of disagreement and they take opposite remedies, so the check names which one
you have rather than assuming Latvian's shape. Do not reach for the `lv` fix by reflex.

**A PERMUTATION disagreement** — the two partition the counts into the same groups and only
label them differently. Reordering the arrays makes the locale fully correct:

1. Order the **arrays** by the library, not the header.
2. Record `"pluralOrder": "library"` in `locales/$LOC.json`.

Only `lv` is this: its header carries the legacy gettext order `[one, other, zero]` while the
library partitions `[zero, one, other]` — same three categories, rotated, so a file matching
its own header was wrong at **every** count while passing every other gate.

**A BOUNDARY disagreement** — the two draw the lines in different *places*, so **no
permutation of the arrays can agree with the header everywhere**. There is nothing to
reorder, and "order the arrays by the library" is meaningless advice here. What you actually
choose is *which counts to be correct for*:

1. Write each form to read correctly across the counts the library **actually routes to it**,
   weighting by which counts a user plausibly hits.
2. Say in `pluralNote` **which counts stay wrong**, explicitly.
3. Record `"pluralBoundary": "library"` in `locales/$LOC.json`.

Both locales §2.3 had flagged turned out to be this, and **in opposite directions** — which
is the reason to classify rather than pattern-match:

- `is` — the library places `is` in its coarse `number === 1 ? 0 : 1` group, so form 0 is
  reachable **only at exactly 1**. The header is correct CLDR Icelandic
  (`n%10!=1 || n%100==11`), under which 21, 31, 41 … 191 also take the singular
  (`21 hlutur`, not `21 hlutir`). So 17 counts in 0–200 render the plural where Icelandic
  wants the singular. Form 1 is still written as the true plural: it is correct for 0 and
  2–20, which is the overwhelming majority of real counts, and contorting it into a
  number-neutral shape would trade 17 wrong counts for ~180 unidiomatic ones. This is *not*
  the `rm` case — there the collapsed form was wrong at **every** count but 1, so the
  contortion paid.
- `mk` — the mirror image, and much narrower. The library implements the modular rule
  (`number % 10 === 1 ? 0 : 1`) but **drops the `n%100 !== 11` guard**, so only `11` and
  `111` disagree, and they go the other way: the library selects the **singular** where
  Macedonian takes the plural.

The general lesson: a two-form header whose expression is **modular rather than `n != 1`** is
the shape to check, because the library's coarse groups are mostly written as `n === 1`.
`lb` and `sq` carry plain `n != 1` headers and agree exactly; `bs` and `be` are three-form
Slavic and also agree.

A **NOTE** that the library uses *fewer* forms than declared is a third, separate situation —
see below.

**A locale can also come out with no plural surprise at all, and `mt` is the first.**
Header and library were compared index-by-index over counts 0–130 and agreed everywhere,
with all four declared forms reachable — no reordering as in `lv`, no collapsed form count
as in `rm`/`ga`/`tr`. Do not read that as "the arrays are easy": Maltese's four forms are
Semitic rather than European (§7.1), and the flagged "suspect array" in that bundle turned
out to be **correct** and was left alone. Run the check to find out which situation you are
in; do not infer it from the header's shape.

A **NOTE** that the library uses *fewer* forms than declared (`tr`, `rm`, `ga`) is
harmless **only when a single form is correct in that language anyway**. Check which
case you are in, because the two look identical in the tool output — and **check it by
measuring core, not by reasoning about the grammar**:

- `tr` — genuinely harmless. Turkish does not pluralise after a numeral, so `5 dosya` is
  correct Turkish and the dead form was never needed.
- `ga` — harmless, but only demonstrably so. The header declares **five** forms and the
  library reaches **three** (index 0 ← `n==1`, index 1 ← `n==2`, index 2 ← `0` and every
  `n>=3`), so forms 3 and 4 are dead and index 2 must serve 0, 3–6, 7–10 and 11+ alike.
  Irish morphology genuinely distinguishes all five after a *spelled-out* numeral —
  `trí` lenites, `seacht` eclipses — which makes this look like the `rm` case. It is not,
  and the way to establish that is to count: across core `ga`'s 101 fully-translated
  five-form arrays, form 2 differs from form 3 in **zero** cases and form 3 from form 4 in
  **zero**. Core never applies the mutation after a digit (form 0 is `%n comhad`,
  `%n carachtar`, `%n beart`, never `chomhad`), so the three reachable forms are all core
  ever writes. Had that count come out differently, `ga` would have needed the `rm`
  treatment.
- `rm` — **not harmless.** `@nextcloud/l10n` has no Romansh entry, so `getPlural`
  returns 0 at every count and form 1 is unreachable — but Romansh pluralises regularly
  with `+s`, so a bare singular in form 0 renders `5 datoteca`, wrong at every count but
  1. Nothing flags it: the arity is right, no value is empty, and the file reads fine.

Where the collapsed form is not correct on its own, form 0 has to be written to work at
**every** count. `rm` uses the `(s)` parenthetical that its bundle already applied to the
sibling `(s)` keys (`Stizzar {count} object(s)`), and a number-neutral phrasing for the
one plural key with no numeral, since a parenthetical cannot span three agreeing words.
Write form 1 as the true plural anyway, so the array becomes correct if the library ever
gains an entry. Do not add `pluralOrder` — there is no ordering disagreement here, only a
smaller form count.

This also applies to a plural array that was already in the bundle: `rm`'s pre-existing
`_%n entry has no hash yet_` carried a singular noun **and** a singular verb in form 0,
so it rendered `5 endataziun n'ha` — wrong twice at every count but 1, and it had passed
every gate for the life of the file.

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

**This is a required step of every pass, not something you do when you happen to notice
something.** The `is` pass first scope-limited itself to "a systematic error with a
core-confirmed model, or a collision, and otherwise leave a real translation alone", and the
owner rejected that line. Reading the whole pre-existing half then turned up **235 further
defects in 1052 values** — wrong noun gender, wrong case after a governing verb, malformed
compounds, garbled words, two stems from other languages, and a dozen terms that contradicted
the bundle's own vocabulary. None of them is visible to any gate: a wrongly-inflected value
is not empty, not identical to English, not wrong-arity, and reads as finished work.

**§3.8 guards against changes of taste, not against fixing grammar.** Do not cite it to skip
the audit.

**Mechanical checks are necessary but nowhere near sufficient.** On `is` they found 4 of the
239. Build them anyway — they catch the class a reader's eye slides over — but then *read
every pre-existing value*. What the checker cannot see is exactly what dominated: a bad
compound, a nonsense word, a foreign stem, a right word in the wrong sense.

Two things to get right when writing the checks, both learned the hard way on `is`:

- **Know which forms are homographous in the target language before flagging disagreement.**
  In the Icelandic strong declension the **neuter plural nominative is identical to the
  feminine singular**, so `Öll skemu`, `Engin mál` and `Möguleg tvítök` are all correct. The
  first version of the check treated each adjective form as licensing one gender/number pair
  and produced 30-odd false positives that buried the four real findings. Map each form to a
  **set** of permitted pairs. Also: never look across punctuation (a comma or colon ends the
  phrase), and skip a participle used as a supine after a modal, where it does not agree with
  the following noun at all.
- **Case-governance checks only work where the cases differ.** Feminine and neuter singulars
  are syncretic for nominative/accusative/dative in most Icelandic declensions, so
  `bæta við skrá` *is* the dative and flagging it is pure noise — all 24 hits from the first
  attempt were false. Restrict such a check to masculine singulars (`-ur`/`-ll`/`-nn`) and to
  plurals (dative `-um`), where the surface forms actually discriminate. Watch for phrasal
  verbs too: `búa til` is "create", and the `til` in it governs nothing.

Record every correction in `corrections` (§6.3). At this volume, per-key prose stops being
readable — use **short class codes** (`AGREEMENT`, `CASE`, `NUMBER`, `COMPOUND`, `HYPHEN`,
`TYPO`, `GARBLED-OR-FOREIGN`, `SENSE`, `TERM-*`, `CONSISTENCY`) and document the codes once in
a free-form field, as `locales/is.json` does.

Fix each one through the audited path (§6.3), with the reason in `corrections`. Recent examples:
`sl` carried `Revizijski trag` for *audit trail* where `trag` is **Croatian** (Slovenian is
`sled`) and one dialog used `Predmeti` against 74 uses of `objekt`; `lv` had 78 register
deviations; `et` had 24; `sr` had nine, spanning four different defect classes at once
(wrong alphabet, Croatian, register, and capitalisation).

**Check what makes a value wrong before calling it wrong — the same shape can be correct in
one locale and a defect in the next.** Two cases from `sr`, both worth internalising:

- `Изабери модел или унеси прилагођени назив модела` **looks** like the informal slip its two
  siblings were, and is not: `унеси` is a 2sg *imperative*, which is Serbian's label
  convention, where the siblings carried 2sg *presents* (`имаш`, `сачуваш`). One is style, the
  other is address. Recorded in `UNDETECTABLE` rather than "fixed".
- `Write an audit-trail entry for every step` opens with a bare 2sg imperative in **both**
  `bg` and `sr`. In `bg` that had to be rewritten as a verbal noun; in `sr` it is correct and
  was left alone. Same English source, same apparent shape, opposite verdicts — decided by
  each locale's measured button convention (§7.3) and nothing else.

Two judgement calls:

- **Register deviations are not optional cleanup.** Leaving them makes the bundle mix
  registers inside a single dialog, which reads worse than either choice made consistently.
- **A mild inconsistency is not automatically a defect.** Only replace a real value when it
  is genuinely wrong, and say why. `hr` kept `lozinka` over core's preferred `zaporka`
  because the file already shipped it and it is valid Croatian.

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

Then confirm the totals match the sequential run (`999 new, 15 replaced` for `sk`).

Prevention: `cp` after every batch, and restore with `cp`, never with git. The one place
this hazard used to be unavoidable — the gate negative test — is now designed out:
`l10n:gatetest` snapshots and restores the bundle itself, so there is no hand-rolled
break-and-undo cycle to get wrong.

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
locale one key short. It has already happened once, from a merge adding two calls.

```bash
npm run test:l10n            # names the keys en.js is missing
npm run test:l10n:write      # extracts them into en.js
```

Then, for each new key, make the §3.2 decision **before** translating anything:

- **Non-prose** (a placeholder, an example value, a bare product name) → **unwrap it in
  `src/`** and do not add the key at all. If `test:l10n:write` already added it, remove it
  from `en.js` too. This is the right answer more often than it looks: a label that is just
  a product name is not a translatable string.
- **Real prose** → it needs a value in **every finished locale**, or parity breaks. Use
  `l10n-ai.js add <key> --value en=… --value nl=… …`, or a small `apply.js` patch per
  locale. Check `l10n-ai.js get <similar-key>` first — a sibling key often already tells you
  each locale's term.

For a **rename**, `l10n-ai.js rename` handles all 37 bundles but **not the call site** —
grep `src/`. For a **removal**, `rm` the key everywhere in the same commit, or `check:l10n`
reports it unused forever.

Worked example: a merge added `OpenCorporates` and `KvK Company Register`. The first was
unwrapped in `src/` — no locale had ever carried a value differing from the bare product
name, which is the §3.3 test — and the second was translated into all finished locales,
because it is real prose and twelve locales already translated its sibling keys.

---

## 7. Per-locale reference data

`docs/l10n-ui-translation.md` is the full version. This is the part you need in front of you
while writing arrays.

### 7.1 Plurals

Take `nplurals` from the locale file's **own** header and build against **that locale's
expression**. Equal counts do not mean equal boundaries, and there are three separate ways
this goes wrong:

| Locale | Forms | Boundaries |
| --- | --- | --- |
| `hr` `ru` `sr` `be` `bs` | 3 | modular: `1,21` / **2–4** / `0,5–20`. `sr` verified over 1–1001: 22 → form 1, 111 → form 2 |
| `lt` | 3 | modular, **wider form 1**: `1,21` / **2–9** / `0,10–20` |
| `pl` | 3 | modular, `n==1` exact for form 0 |
| `cs` `sk` | 3 | **absolute**: `1` / `2–4` / everything else incl. 0 |
| `lv` | 3 | `1,21` / nonzero / **dedicated zero form** — and the ORDER disagrees (§6.7) |
| `ro` | 3 | `1` only / **0 and 2–19** / 20+ |
| `sl` | **4** | modular on `n%100`: `1` / **2 = DUAL** / `3,4` / else incl. 0 |
| `is` | 2 | header is modular (`n%10!=1 \|\| n%100==11`), so 1, 21, 31 … take the singular — but the library reaches form 0 **only at n=1**, a BOUNDARY disagreement no reordering fixes (§6.7). 17 counts in 0–200 render the plural where Icelandic wants the singular |
| `mk` | 2 | same modular header as `is`, but the library **drops the `n%100!=11` guard**, so only 11 and 111 disagree — and in the opposite direction, selecting the singular where Macedonian takes the plural (§6.7) |
| `ga` | 5 | header 5, library reaches **3**: `1` / `2` / `0` and all `n>=3`. Forms 3–4 dead, harmlessly (§6.7). What decides the arrays is the **counted-noun rule**, not the index — see below |
| `mt` | 4 | **Semitic, not European**: `1` / `0 and n%100 2–10` / `n%100 11–19` / `20+`. The noun is PLURAL only in form 1 — forms 0, 2 and 3 all take the SINGULAR (`ħdax-il ktieb`, `għoxrin ktieb`), so three near-identical forms are correct, not a defect. Header and library agree exactly |
| `bg` | 2 | plain `n != 1` — but see the **count form** hazard below |
| `rm` | 2 declared | the library knows no Romansh and returns **form 0 at every count**, so form 1 is unreachable — see the collapsed-form hazard below |

The distinct hazards, every one of which has actually bitten (deliberately not numbered —
the list has grown three times):

- **Wrong boundary.** A Croatian array pasted into Lithuanian is wrong for 5–9.
- **The library's boundaries not matching the header's**, with no reordering available to
  reconcile them. Distinct from the `lv` permutation and from the `rm` collapse, and the
  remedy is to pick which counts to be correct for and record the residue — §6.7. `is` and
  `mk` are both this, in opposite directions. **The shape to check for is a two-form header
  whose expression is modular rather than `n != 1`**, since the library's coarse groups are
  mostly written `n === 1`.
- **Absolute vs modular.** `sk`/`cs` bound absolutely, so **22 selects form 2** ("22
  objektov", correct Slovak); `hr` bounds modularly, so 22 selects form 1. An array copied
  between two `nplurals=3` Slavic locales is wrong at every compound number.
- **The dual.** Slovenian's form 1 is a real dual: it governs noun case, adjective agreement
  **and verb number** together, so 2 needs `Objekta sta bila izbrisana` where the plural
  needs `Objekti so bili izbrisani`.
- **A separate counting form, chosen by the SENTENCE and not by the form index.** This one is
  lexical rather than structural, so `nplurals` gives no warning at all — `bg` has the
  simplest header in the set and still needs it. Bulgarian masculine nouns that do not denote
  persons take a **count form** (числителна форма, `-а`/`-я`) after a numeral, distinct from
  the ordinary plural: `обект` → `обекти` as a bare plural but `обекта` after a number, and
  likewise `запис`/`записа`, `файл`/`файла`, `регистър`/`регистъра`. So which noun form form 1
  needs depends on **whether the string contains a numeral**:

  ```js
  "_Delete {count} object_::_Delete {count} objects_":            // numeral present
      ["Изтриване на {count} обект", "Изтриване на {count} обекта"]
  "_Object successfully deleted_::_Objects successfully deleted_": // no numeral
      ["Обектът е изтрит успешно", "Обектите са изтрити успешно"]
  ```

  Two keys, one locale, one form index, two different noun forms. Masculine **person** nouns
  keep the plural (`{count} членове`), and feminine/neuter have no count form at all
  (`схема` → `схеми` either way). The pre-existing `_%n entry has no hash yet_` array already
  had `%n записа` and is the model. Look for this in any locale whose grammar has a
  paucal/counting form — it is invisible to every gate in the project.

- **A SEMITIC four-form system, where three of the four take the singular.** Maltese
  pluralises the noun **only** after 2–10 (and 0); after 1, after 11–19 (`ħdax-il ktieb`)
  and after 20+ (`għoxrin ktieb`) it takes the SINGULAR. So `mt`'s forms 0, 2 and 3 are
  normally the same string and only form 1 differs — which looks exactly like three
  duplicated forms and is not. §2.3 had flagged this bundle's one pre-existing array as
  "suspect, forms 2 and 3 fall back to the singular"; verifying it showed the suspicion was
  unfounded and it was left untouched. The harvest independently corroborated the rule:
  `Last 7 days` → `L-aħħar 7 ijiem` (plural) against `Last 30 days` → `L-aħħar 30 jum`
  (singular). One further trap in that array: **the whole predicate agrees, not just the
  noun** — `%n entrata għadha m'għandhiex hash` against `%n entrati għadhom m'għandhomx
  hash` — so an array here cannot be built by swapping the noun alone.

- **The numeral forcing the SINGULAR, which is the mirror image of the above.** Irish takes
  the *singular* noun after a numeral (An Caighdeán Oifigiúil: numerals 1–19 govern the
  singular), so for `ga` the split is again decided by whether the value contains a numeral,
  but in the opposite direction from `bg`: **numeral present → the same counted singular in
  every form; no numeral → a genuine singular/plural split.**

  ```js
  "_Delete {count} object_::_Delete {count} objects_":              // numeral present
      ["Scrios {count} réad", …]                                    // identical ×5
  "_Object successfully deleted_::_Objects successfully deleted_":   // no numeral
      ["Scriosadh an réad go rathúil", "Scriosadh na réada go rathúil", …]
  ```

  Core's own data separates cleanly on exactly that axis, which is how the rule was settled
  rather than assumed: of 73 numeral-bearing arrays **53 keep the singular** in every form
  against 20 that pluralise (the calqued minority), while **28 of 28** arrays with no numeral
  pluralise. So five of this bundle's seven arrays are legitimately all-forms-identical — the
  `hu`/`tr` shape, and the reason `selfcheck` NOTEs 5 of 7 here. Initial mutation is **not**
  applied after a digit (core writes `%n comhad`, never `chomhad`), but a *fixed* numeral in
  a label does take it (`Last 3 months` → `3 mhí anuas`, from `trí mhí`) — the difference
  being whether the number is known at authoring time.

- **The library collapses every count onto form 0, in a language that pluralises.**
  `@nextcloud/l10n` has no entry for Romansh, so `getPlural` returns 0 at every count and
  form 1 is dead. That is the same *symptom* as `tr`, where it is harmless because Turkish
  does not pluralise after a numeral — but Romansh pluralises regularly with `+s`, so a
  bare singular in form 0 renders `5 datoteca`. **The fix is in form 0, not in the
  arity**: it has to be a shape that is correct at every count. `rm` uses the `(s)`
  parenthetical its bundle already applied to the sibling `(s)` keys
  (`Stizzar {count} object(s)`), and a number-neutral phrasing (`Stizzà cun success`) for
  the one plural key with no numeral, because a parenthetical cannot span three agreeing
  words. Form 1 is still written as the true plural so the array becomes correct if the
  library ever gains an entry. See §6.7 for how to tell the harmless case from this one.

`test:l10n:parity` catches wrong **length** only. Nothing catches a wrong boundary except
`l10n:runtime` on this locale's own counts, and **nothing at all** catches a form 0 that
is only correct at count 1.

**The `n()` catalogue key is NEITHER source string.** It is the identifier
`"_<singular>_::_<plural>_"` — see `pluralIdentifier` in `lib.js`. Storing forms under the
bare singular renders correctly for `count === 1` and falls back to English for every other
count. That shape shipped in all 37 bundles until it was fixed, passing every gate the whole
time.

**At runtime the index comes from the library, not the header.** `register(app, bundle)`
**ignores** a plural function passed as a third argument. The header governs the arity gate;
the library governs which element renders. `runtime-check.mjs` calls `unregister()` and
`setLanguage(loc)` first for this reason.

### 7.2 Register verdicts, all measured

Informal: `nl de sv da nb pl fi hu et lv ga mt is`.
Formal: `fr cs ru uk tr el sr bg ca hr lt ro sk sl`.

**`ga` is in the informal column for a different reason from every other entry in it**, and
copying the label without the reason would be a real error: Irish has **no T-V distinction**,
so there is no choice being recorded. See the note under §3.4 and the `ga` row below.

**`mt` looks like `ga` and is not** — the two are worth contrasting, because it is exactly
the inference to avoid. Maltese **does** have a politeness system: `intom` serves as a
polite singular the way French `vous` does, and `Is-Sinjur`/`Is-Sinjura` with third-person
agreement is the deferential register. Both exist and both are simply unused, so `mt`'s
`informal` is an ordinary measured choice like `nl`'s, and its gate catches genuine
deference rather than an impossible form. Two locales in a row measuring 2sg-only does not
mean they measured the same thing.

| Locale | Prose | Evidence |
| --- | --- | --- |
| `sk` | formal | **1001 vs 1** over 4991 values / 31 catalogues — the clearest of any |
| `tr` | formal | 841 vs 0 |
| `hr` | formal | 744 vs 1 (and the 1 is an example email address) |
| `lt` | formal | 689 vs 0 |
| `ca` | formal | 491 vs 32 |
| `et` | **informal** | 415 vs 3 — core overruled the file |
| `sl` | formal | 304 vs 0 |
| `sr` | formal | 911 vs 0 over 4631 values / 32 catalogues |
| `bg` | formal | 699 vs 43 — but the 43 needs splitting; **prose is 699 vs 11** |
| `ro` | formal | core **MIXED** 124 vs 66 → decided by the bundle (84 vs 0) + owner |
| `lv` | **informal** | 44 vs 3 — core overruled the file; 78 values corrected |
| `is` | informal | **626 vs 0** over core's 28 catalogues / 3610 values, plus 0 formal in the bundle's own 1054. The zero was re-checked by raw grep across nine V-form and 2pl tokens (`yður yðar yðvar yðr þið ykkur ykkar þéra þérun`), all literally absent. A THIRD kind of "informal": Icelandic *had* a T-V distinction and abandoned it in the 20th century, so `yðar` is archaic rather than absent (`ga`) or merely unused (`mt`) |
| `rm` | formal | **81 vs 0** over the bundle's own 995 translated values — core ships **no `rm` catalogues at all**, so this is the §6.4 fallback rather than a core measurement |
| `ga` | **no T-V distinction** | **440 vs 0** over 5395 values / 33 catalogues — the most one-sided of any locale, and structurally so: `sibh` is strictly plural in modern Irish, so 2pl address does not occur at all. Recorded as `informal` to set the gate's polarity against 2pl address, which for a single-user UI is always a defect |
| `mt` | informal | **128 vs 0** over 3422 values — core ships **no `mt` catalogues at all**, so this is the §6.4 fallback widened to the sibling apps' frontend bundles. Unlike `ga`, Maltese HAS `intom` and `Is-Sinjur` available; they are measured absent, so this is a real choice. Markers: 75 `tiegħek`, 35 `jekk jogħġbok`, 15 `int`/`inti`, 4 prepositional |

Latvian's low counts are structural, not weak evidence: most correct informal Latvian is
undetectable by design, so **zero formal markers is the assertion that matters**, not a high
informal count.

**Split the informal count by what the hit IS before reading a verdict.** For `bg`, 29 of the
43 informal hits are core using a 2sg imperative as a *button label* (`Копирай`,
`Актуализирай`, `Преименувай`); only 11 are informal **prose** (a `ти`/`теб`/`твой` pronoun or
a 2sg present), and those cluster in two old catalogues. Unsplit, 43 looks like a real
minority position worth weighing; split, it is a label-style choice plus eleven legacy
strings. This matters most where the scan comes out closer than `bg` did — `ro`'s MIXED 124
vs 66 (§6.4) is exactly the shape that deserves the same treatment before anyone is asked to
decide.

### 7.3 Button conventions — four patterns

| Pattern | Locales | Example |
| --- | --- | --- |
| same register as prose | `tr` `ru` | formal imperative |
| bare 2sg imperative, whatever the prose | `ca` `et` `hr` `sl` `sr` `ga` `mt` | `Desa`, `Salvesta`, `Spremi`, `Shrani`, `Сачувај`, `Sábháil`, `Issejvja` |
| **infinitive — register-neutral** | `cs` `lt` `lv` `sk` `rm` `is` | `Zobrazit`, `Įrašyti`, `Saglabāt`, `Uložiť`, `Memorisar`, `Vista` |
| **verbal noun — register-neutral** | `ro` `bg` | `Salvare`, `Adăugare endpoint`; `Запазване`, `Добавяне на крайна точка` |

Infinitive buttons must **not** be "corrected" to imperatives.

`bg` reaches the verbal noun for a different reason than `ro`, and the difference is worth
keeping straight. Bulgarian **has no infinitive**, so the verbal noun (отглаголно
съществително, `-не`) is the only register-neutral form available — it does the job the
infinitive does in `cs`/`lt`/`lv`/`sk`. Core `bg` is genuinely mixed about it (44
catalogue-weighted verbal nouns against 23 imperatives, 4 formal 2pl and 4 plain nouns), so
the measurement alone does not settle it; the tie is broken by the file, whose 1053
pre-existing values are verbal nouns and plain nouns without a single imperative label. That
is the ordinary §3.5 rule, not a divergence — unlike `ro`, where the convention **knowingly
contradicts** core because the dual-role `Create`/`Read`/`Update`/`Delete` keys force it.

`ro` is the only locale where the convention is a **project decision that knowingly diverges
from core**, and it was forced: `Create`/`Read`/`Update`/`Delete` are single keys rendered
both as `<th>` column headers *and* as buttons, so a header reading `Ștergeți` ("delete!")
above a count column would be wrong. Those four force the verbal noun and the rest follow.
Same open defect class as `Url` vs `URL` (§10).

### 7.4 The `{plural}` source hack — KNOWN DEFECT, note it and move on

**The owner is aware of this and will fix it once all translations are finished** (decided
2026-08-19). So: pick a reasonable shape for the locale, record it in `pluralHackNote`, and
**do not spend pass effort on it**. Do not weigh parenthetical against slash against bare
plural noun-by-noun, do not treat an awkward rendering here as a defect worth escalating, and
do not flag it in the commit message. The same applies to the sibling `(s)` keys
(`register(s)`, `schema(s)`, `configuration(s)`) and to plain `{count} X` phrases, which have
the identical problem for the identical reason.

The rest of this section is the record of what the finished locales already did, kept because
it is useful when picking a shape quickly — not an invitation to optimise.

The source hardcodes English morphology: 13 call sites pass `plural: count !== 1 ? 's' : ''`
for five keys. What each finished locale does:

| Shape | Locales | Example |
| --- | --- | --- |
| keep the placeholder — plural really is `+s` | `es`, `ca` (4 of 5) | `fitxer{plural}` |
| parenthetical | `nl de fi ru pl cs et sk sl` | `bestand(en)`, `súbor(y)`, `datoteka(-e)` |
| slash, where the stem changes | `fr`, `ca`, `et` | `journal/journaux` |
| bare noun — no plural after a numeral | `hu` `tr` `ga` | `fájl`, `dosya`, `comhad` |
| **parenthetical AND slash, mixed per noun** | `is` | `skrá(r)` where the plural is stem+`r`, but `hlutur/hlutir` and `skema/skemu` where the stem changes |
| the form correct for the most counts | `hr` `lv` `ro` | gender-dependent |
| genitive plural, conventional invariant counter | `lt` | `failų` |

**Reuse the bundle's own house style if it has one.** `sk` and `sl` both already had
`register(s)` → `register(-tre)` / `register(-i)` for the sibling `(s)` keys, so those two
keys are literally the pre-existing values.

**But grammar outranks the house style when the two conflict.** `ga`'s bundle already had
the sibling `(s)` keys as parentheticals (`register(s)` → `clár(acha)`, `schema(s)` →
`scéimre(í)`), and the `{plural}` keys still took the **bare counted singular** instead —
because every call site renders the label beside a numeral (`CnStatsBlock`'s `:count` and
`:countLabel`), and Irish takes the singular after a numeral, so a parenthetical there
would be *wrong* rather than merely unidiomatic. Check what precedes the placeholder at the
call site before reaching for the sibling keys' shape. The pre-existing `(s)` values are
left alone under §3.8; the consistency that matters is with the `{count}` arrays, which
apply the same counted-noun rule.

**A locale can legitimately need MORE THAN ONE of these shapes, chosen per noun.** `is` is
the case: its bundle's house style is the parenthetical (`stilling(ar)`, `skrá(r)`), and
that works wherever the plural is the stem plus `-r` — so `file{plural}` → `skrá(r)` and
`register{plural}` → `gagnaskrá(r)`. But where the stem changes it produces a non-word, so
`object{plural}` → `hlutur/hlutir`, `log{plural}` → `annáll/annálar` and `schema{plural}` →
`skema/skemu` take the slash instead. The pre-existing `schema(s)` → `skema(r)` in that
bundle is exactly the error this avoids: the plural of `skema` is `skemu` and `skemar` is
not a word. Pick the shape per noun, not per locale.

Always runtime-assert no `{plural}` residue and no stray trailing `-s` survives. And note
that a locale which **keeps** `{plural}` is not broken — `es` keeps it in all five, `ca` in
four. Any assertion about those keys must branch on whether the placeholder survived:
kept → the value **must** vary with count; dropped → it **cannot**.

---

## 8. Traps catalogue

### 8.1 Never use suffix patterns for register detection

Every one of these produced a wrong measurement:

| Language | Suffix that looks like a marker | What it actually also is |
| --- | --- | --- |
| `hr` `sk` `sl` | `-š` (2sg present) | **`vaš` = your-FORMAL**, and `naš` = our → polarity inverted outright |
| `hr` | `-te` (2pl) | accusative plural of every masculine noun (`dokumente`) |
| `sk` | `-te` (2pl) | locative singular of every hard masculine noun (`v dokumente`), and `ešte` = "still" |
| `sl` | `-te` (2pl) | accusative plural of `ta` (`te datoteke` = these files) **and** accusative of informal `ti` |
| `lt` | `-ai` (2sg present) | nominative plural of every masculine noun (`objektai`) and a large adverb class (`gerai`) |
| `lv` | `-at`/`-āt` (2pl present) | the **infinitive** — all 12 distinct such words in core lv are infinitives or adverbs |
| `lv` | `-i` | nominative plural of masculine nouns (`faili`) |
| `lv` | `-iet` (2pl) | half false — `vienuviet` is an adverb, `nešķiet` third person |
| `et` | `-ge`/`-ke` (2pl) | ordinary words (`selge`, `märge`) |
| `ro` | `-ați`/`-eți` (2pl) | masculine plural of many adjectives/nouns (`curați`, `pereți`) |
| `sk` | `vy` unguarded | **the most productive verbal prefix in the language** (`vybrať`, `vymazať`, `vytvoriť`) |
| `rm` | `-ai` (2pl polite imperative) | `quai` = *this/that*, **23 occurrences** and the most common word such a rule would hit; plus `perquai` (*therefore*), `mai` (*never*), bare `ai` (a + ils), `hai`/`sai` (1sg) |
| `rm` | `-ais` (2pl present) | `mais` = *months* (`Mintga mais`), and the nationality adjectives `ollandais`, `englais`, `franzais` |
| `rm` | `-as` (2sg present) | **the feminine plural of every noun and adjective** — `controllas` = *checks*, `empruvas` = *attempts*, `tschernas` = plural of the noun `tscherna`. Costs the whole regular paradigm (§6.5) |
| `rm` | `-a` (2sg imperative) | the **3sg present** and the feminine singular past participle, both live in this bundle's prose (`Quai stizza…`, `Ferma mintga flux`) |
| `ga` | `-igí`/`-aigí` (2pl imperative) | the plural of every noun in `-ig` — `oifig` → `oifigí` ("offices"). **No such word occurs in the 6431-value corpus**, which is exactly why a suffix rule here would have looked safe and shipped |
| `ga` | `-ibh` (2pl prepositional pronoun) | third-person plurals one letter away: `díbh` is "off you (pl)" but `díobh` is "off **them**", and it is the third-person one that occurs here, twice, both genuinely "of them" (`gach ball díobh seo`) |
| `ga` | `do` (2sg possessive "your") | the preposition *to/for*, the past-tense verbal particle, and half of `le do thoil` ("please") — **527 of 6431 values**, overwhelmingly not possessive. Excluded wholesale; the biggest single recall loss in any detector here |
| `mt` | `t-` prefix (2sg imperfect) | the **3sg FEMININE** imperfect, identically spelled across the whole verb system. `tista'` is "you can" AND "she/it can", and BOTH readings occur here — "Hawn tista' tara" against "Il-Proprjetà tista' tittejjeb". 23 occurrences split both ways, plus 24 of `trid` |
| `mt` | `-u` (2pl imperative / 2pl present) | the **3pl of everything**. `nstabu` ("they were found") occurs 24 times in "Ma nstabu l-ebda X", `għandhom` 19 times, plus `jistgħu`, `jappartjenu`. A `-u` rule scores the commonest sentence shape in the file as deference |
| `mt` | `-kom` / `-ek` (2pl / 2sg object) | ordinary word endings. Both paradigms are small and closed, so both are enumerated instead — thirteen prepositional pronouns each side |
| `mt` | `-t` (2sg perfect) | also the **1sg perfect**; the two differ only by an internal vowel (`ħlaqt` "I created" vs `ħloqt` "you created"), far too fine for a word list |
| `is` | `-ið` (2pl verb ending) | the **neuter DEFINITE ARTICLE**, one of the commonest morphemes in the language — `lykilorðið`, `tölvupóstfangið`, `skjalið`, `safnið`, `yfirlitið`, `nafnið` are all nouns. The bg `-те` situation and slightly worse, because five individual 2pl verb forms are *themselves* homographs of ordinary words: `hafið` is "the ocean" **and** the past participle of `hefja`, `getið` is the participle "mentioned", `verðið` is "the price", `vitið` is "the wit", `eigið` is the neuter adjective "own". Two of those five occur in core in the non-verb reading (`hefur hafið ferli`, `þitt eigið Nextcloud`) and **none** occurs as a 2pl verb |
| `is` | `-ur` (2sg present) | the **masculine nominative singular** of thousands of nouns, *and* Icelandic syncretises 2sg with 3sg for most verbs anyway (`þú getur` / `hann getur`), so the ending carries no address information even when it is verbal |
| `is` | `-ðu`/`-tu` (2sg imperative + enclitic pronoun) | the **3rd person plural past** for the whole class-2 conjugation. Usable for class 1 and the ablauting strong verbs, unusable otherwise — the split is by conjugation class and is worked through in §6.5 |

Use closed word lists. Always.

**Look for the locale's POLITENESS FORMULA, not just its pronouns.** `mt` is where this
paid: `jekk jogħġbok` ("please") carries a 2sg **object suffix**, which makes it a genuine
address marker rather than a bare courtesy word — and at 35 uses it was the second
commonest marker in the bundle, behind only the possessive. Its 2pl counterpart
`jogħġobkom` is the unambiguous deferential form and belongs in the formal list. The first
draft of `detectors/mt.js` omitted both and a control caught it. Any locale whose "please"
inflects for the addressee has the same free signal: `ga`'s `le do thoil` does **not**
(it inflects nothing detectable — see the `do` row above), and neither does `is` — Icelandic
`vinsamlegast` is an adverb (a superlative of `vinsamlegur`, "kindly") and inflects for
nothing at all. Two of the three checked so far came out empty, so **check rather than
assume in either direction**; the check is cheap and the payoff when it lands is large.

### 8.2 Pronoun homographs, per language

| Language | Collision | Resolution |
| --- | --- | --- |
| `cs` `hr` `sl` | `ty`/`ti` = informal *you* **and** the plural demonstrative *those* | leave the bare pronoun unmatched; use oblique forms and the possessive |
| `sl` | `te` has **three** readings: acc. of `ti`, acc. plural of `ta`, and the 2pl ending | leave unmatched |
| `hr` `sk` `sl` | `si` = 2sg of *to be* **and** the reflexive dative clitic, commonest in **formal** prose | leave bare `si` unmatched |
| `sl` | `vas` = formal *you* **and** the noun *village* | kept; implausible in this domain, recorded in `UNDETECTABLE` |
| `da` `nb` | `De`/`Dem`/`Deres` = formal *you* **and** everyday *they/them/their* | require a **mid-sentence capital** |
| `pl` | `Państwo` = formal plural *you* **and** the noun *state* | mid-sentence capital |
| `ru` | `вы`/`ваш` are ordinary polite address, not plural-only | do not match at all |
| `et` | `teist` = elative of `teie` **and** partitive of `teine` ("another") | exclude entirely — it was **half** of core's apparent formal signal |
| `tr` | 2sg possessive == plural genitive (`dosyaların`) | all 35 first-pass hits were this; only `şifre`/`parola` are safe |
| `lt` | `gali`/`turi`/`nori` are 2sg **and** 3sg/3pl — no number distinction in the third person | exclude all three; use `žinai`, `matai`, `gauni`, `esi` |
| `lv` | for `-ēt`/`-āt` verbs the 2sg **is** the third person (`meklē`) | systematic, not exceptional — see the docs |
| `lv` | feminine `-e` nouns have accusative `-i`, colliding with the imperative (`pārbaudi`) | same |
| `ro` | `vă` (formal) vs `va` (3sg future auxiliary), one diacritic apart | match only `vă` |
| `ro` | `ai` = 2sg of *avea*, the possessive article, **and** the acronym **AI** under folding | exclude |
| `bg` | `те` = 2sg accusative clitic **and** the 3pl pronoun *they* | leave unmatched — the bundle says "Те могат да бъдат възстановени" *of objects* |
| `bg` | `-те` is the 2pl verb ending **and** the **definite plural article**, the commonest morpheme in the language | never a suffix rule; `файловете`, `Членовете`, `настройките` are all nouns |
| `bg` | `си` = 2sg of *to be* **and** the reflexive possessive clitic, commonest in **formal** prose | leave unmatched, as in `hr`/`sk`/`sl` |
| `bg` | `трябва` looks like address but is **impersonal 3sg** | do not match; in `Трябва да сте влезли` the register is carried by `сте` |

| `ga` | `tú`/`thú` — **no collision at all** | nothing; Irish has no demonstrative or copular reading of these, so the bare pronoun is fully usable. Same useful negative as `bg` and `rm` |
| `ga` | `Dia duit` ("hello"), `Fáilte romhat` ("welcome") | fixed greetings, but genuinely 2sg address, so counting them is correct rather than a false positive |
| `mt` | `int` / `inti` — **no collision** | nothing; the bare pronoun is fully usable. But it is only 15 of 128 hits, so do not build the detector on it |
| `mt` | `tagħhom` (3pl "their") vs `tagħkom` (2pl "your") | one paradigm slot apart, and the 3pl is the one that occurs — `mar-ringieli tagħhom`, `il-konfigurazzjonijiet tagħhom`. Match only `tagħkom` |
| `mt` | `verifika` | "verification" AND, in six pre-existing values, a mistranslation of *audit*. Not a register trap but a lexical one; note `ivverifika` (the verb, legitimate) contains it, so any check must anchor on a word boundary |
| `is` | `þér` = 2sg **DATIVE** *and* the archaic polite **NOMINATIVE** | do **not** exclude it and do not call it formal: all 54 core occurrences are the dative (`þér er ekki heimilt`, `gefur þér`), so it counts as informal. The polite reading is recovered by the BIGRAM `þér` + a finite 2pl verb, matched in both orders — see §6.5. Two ambiguous tokens, jointly unambiguous |
| `is` | `þú`/`þig`/`þinn` — **no collision at all** | nothing; the bare pronoun is fully usable, the same useful negative as `bg`, `rm`, `ga` and `mt`. Do not port the "leave the bare pronoun unmatched" rule from `cs`/`hr`/`sl`. One caveat: core's example address `notandi@þitt-nextcloud.org` matches the possessive, the same shape as the single `hr` informal hit |
| `is` | `þið`/`ykkur`/`ykkar` are the **plain modern 2pl**, not a politeness form | still gated as a defect, because addressing one user as plural is wrong — but it is a *different* mistake from archaic `yðar` deference, and it is the likelier one. Keep the two distinct when reading a detector hit |

The useful **negative** result: bare `ти` **is** usable in Bulgarian, unlike `cs` `ty`, `hr`
`ti` and `sl` `ti`. Bulgarian lost its case system and its plural demonstrative is
`тези`/`тия`, so there is no demonstrative reading to collide with. Do not port the
"leave the bare pronoun unmatched" rule across the family without checking — it costs real
recall where it is not needed.

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
- **Danish opens quotes with `”`**, the glyph English uses to close one.
- **A probe without the `i` flag will under-count and look like a real finding.** The first
  `mt` register probe scored **zero** for the pronoun and would have been recorded that way;
  the bundle's three `Inti ċert li trid…` values are sentence-initial and so capitalised.
  Detectors get this right because `fold()` lowercases, but a throwaway probe written
  alongside one easily does not. Any measurement that comes out at exactly zero deserves a
  second look before it goes into `registerEvidence`.
- **Stripping placeholders inserts whitespace, so whitespace checks must run on the raw
  value.** Replacing `{count}` with a space is the right move for an English-leftover scan
  (otherwise `{schema}` fires on every value that carries it), and it makes a
  doubled-space check report ~195 defects that are entirely its own artefact. Keep both
  forms of the corpus and point each check at the right one.

### 8.4 Wrong-sense harvests — the confirmed offenders

Every one of these passes all automated checks. Read the call site.

| Key | Looks like | Actually is | Where |
| --- | --- | --- | --- |
| `Right` | text alignment (`Desno`, `Rechts`, `По правому краю`) | an **RBAC permission** | `EditOrganisation.vue`, "Special Rights" table `<th>` |
| `Bucket` | an S3 bucket, a basket (`Korv`), a bouquet (`Buket`) | a **histogram bin** | `QualityIndex.vue` fallback table |
| `View` | the noun (`Ogled`, `Visualització`) | an **action button** | `OrganisationsIndex.vue`, `SourcesIndex.vue` row actions |
| `Search` | core's verb (`Poišči`, `Найти`) | a **field/tab label** | `ObjectsList.vue`, `SearchSideBar.vue` |
| `Subject` | a mail subject, a school subject | the **GDPR data subject** | `AvgIndex.vue` column |
| `People` | humans in the abstract (`Ljudje`, `Люди`) | the **`PERSON` entity type**, so *persons* | `EntitiesTab.vue` |
| `Label` vs `Labels` | the same word | a **facet range caption** vs **file tags** — often different words | `EditSchemaProperty.vue` vs `UploadFiles.vue` |
| `Test` | a cognate noun | a **button**, so the verb | `WebhooksIndex.vue` |
| `Revoke` | undo (`Cofnij`), reject (`Avslå`) | **revoking an API token** | `TokensSection.vue` |
| `Quota` | fine | core's value may be storage-specific and far too long | |
| `Interval` | fine | a **date-facet granularity** — collides with `Bucket` if you translate `Bucket` as "interval" | `EditSchemaProperty.vue` |
| `Slug` | a cognate | core `lt` translates it `Trumpinys`; `sl` uses `Oznaka` | |
| `Display Name` | fine | core `ca` has **`Nom d'usuari`** = *username* | |
| `Documentation` | fine | core `et` has "…and guides" | |
| `Avatar` | fine | core `et` has a two-word gloss unfit for a label | |
| `Refresh` | fine | core `tr` has **`Yenlle`**, a typo. Do not take typos | |
| `Mappings` | fine | openconnector `hr` has `Mappingi`, a non-standard transliteration |
| `Handler` | an event/callback handler, so a technical term to borrow | **a person** — the DSAR case handler. It is a `<th>` in the cases table beside `Type`, `Status`, `Deadline`, and `handlerFilterOptions` maps people's names into the filter. Every locale renders it as a person: `Responsable`, `Gestor`, `Bearbeiter`, `Gestionar`. `AvgIndex.vue` | |
| `Apply` | fine | core `ga` has **`Cuir iarratas isteach`** = *submit a job application*. The key is a button in `PermissionMatrix.vue`; the right value is `Cuir i bhFeidhm` | |
| `Labels` | the same word as `Label` | the **file-tag column** `<th>` in `UploadFiles.vue`, beside `File name` and `Size` — so the locale's word for *tags* (`ga` `Clibeanna`), while singular `Label` is a facet range caption in `EditSchemaProperty.vue` (`ga` `Lipéad`). The `Label`/`Labels` split this table predicts is real, and `ga` is where it was first acted on | |

**Sibling apps are not automatically right**, and a whole catalogue can be the wrong
language (§6.6).

### 8.5 Collisions the target language creates

Check that a coinage does not collide with a term the bundle already uses:

- `lt` **entity** → `esybė`, not `subjektas`, because `duomenų subjektas` is the GDPR *data
  subject*.
- `lt` **redaction** → `užtemdymas`, not `redagavimas`, because that is this app's word for
  **Edit**.
- `ro`/`sk`/`sl` **Bucket** → `Segment`/`Pásmo`/`Razred`, never "interval", because
  `Interval` is its own key.
- `sl` **Revoke** → `Odvzemi`, not core's `Prekliči`, which this bundle already uses for
  **Cancel** on the same screen.
- `lt` **Reports** → `Ataskaitos`, to stay distinct from `Pranešimai` (*Notifications*);
  **Approve** `Pritarti` distinct from **Confirm** `Patvirtinti`.
- `bg` **Connections** → `Свързвания`, because `Връзки` is already this bundle's word for
  **Relations** (and for the `Links` entity type), and the Relations tab and the Connections
  sidebar are both object-sidebar surfaces.
- `bg` **Subject** (the GDPR data subject) → `Субект на данните` spelled out, because the
  pre-existing bundle uses bare `субект` for **entity** in 20-odd keys. The `lt` fix — coining
  a different word for *entity* — was not available here: `субект` for entity was already
  shipped, so the qualifier goes on the GDPR sense instead.
- `bg` **Golden record / Winning source / Winning value / survivorship** all take `водещ`
  (`Водещ запис`, `Водещ източник`, `Водеща стойност`) so the MDM vocabulary reads as one
  family, with **Survivor** as `Оцелял запис` where the source distinguishes it.

**The worst version of this is a collision on the app's OWN primary noun, and `is` had it.**
Icelandic `skrá` means both *a file* and *a register/list*, and the pre-existing bundle used
it for both — so four pairs of distinct English keys rendered byte-identically (`All
registers` / `All Files` → `Allar skrár`; `Register` / `File`; `Registers` / `Files`; `No
registers found` / `No files found`), and one value came out unreadable: `No register
objects reference this file` → `Engin skrárhlutar vísa í þessa skrá`, both senses in one
sentence as the same word. Three things made this tractable, and they are the general
recipe:

1. **Core decides which sense cannot move.** Core `is` locks `skrá` = file (`Skrá`, `Skrár`,
   `Skráaforrit`) and ships no `Register`/`Record`/`List` key at all. So *register* was the
   side that had to change.
2. **The bundle usually already contains the answer.** `gagnaskrá` was already in it, in
   exactly this sense (`Manage your data registers` → `Stjórna gagnaskránum þínum`). That is
   §3.5, and it beats coining a word.
3. **Ask the owner when it is the app's primary noun.** This propagated through ~76 keys and
   changed 34 shipped values, which is a terminology decision rather than a translation one —
   the §6.4-step-2 case. The answer and the fact that it was theirs are recorded in
   `lexiconNote`.

Two smaller `is` collisions were settled by core alone with no ask needed: `Slug` shared
`Auðkenni` with `ID` while both are field labels on the *same* `EditSchemaProperty` screen
(→ `Stuttheiti`, the shape core `lt` takes with `Trumpinys`), and `Refresh` shared `Uppfæra`
with `Update` (→ `Endurnýja`, which is core's own word for it).

Some collisions are **unavoidable and should be recorded rather than worked around**: `bg`
renders both **Cancel** and **Denial** `Отказ`, which is the right Bulgarian word in each
case (the dialog button, and the GDPR refusal of a request) and is what core `bg` uses for
Cancel in ten catalogues. They never share a screen. Forcing an artificial distinction would
have made one of the two wrong. `is` has one of these too — `Configurations` and `Settings`
both render `Stillingar`, which is correct for each and is core's word for Settings.

Also watch for locale-specific renderings of acronyms that the bundle has already fixed:
**AI** is `MI` in Latvian and `UI` in Slovenian (*umetna inteligenca*) — but product names
keep the English (`Fireworks AI`, `OpenAI`, `Dolphin AI`). **GDPR** is `BDAR` in Lithuanian,
`VDAR` in Latvian, `RGPD` in Romanian and Catalan.

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
`locales/<loc>.json` — the same standard of evidence.

**The practice is per-locale and so is the term list — measure both.** `sr` and `rm` both
capitalise domain terms mid-sentence, and they disagree about which:

| Locale | Capitalised | Notably NOT |
| --- | --- | --- |
| `sr` | six terms, including `Објекат` 62:0 | `приказ`, `ентитет`, `ток` |
| `rm` | **three** — `Schema` 34:1, `Register` 30:0, `Datoteca` 43:0 | `object` at **0:63**, plus `vista`, `webhook`, `colliaziun`, `endataziun`, `caracteristica` |
| `ga` | **none of its own** — casing mirrors the English source | every term reads MIXED per-term; the convention is conditioned on the key, not the word |
| `mt` | **four** — `Oġġett` 56:2, `Reġistru` 55:0, `Proprjetà` 21:0, `Fajl`/`Fajls` 71:0 | `skema` 4:17, `iskema` 6:49, `veduta` 3:26, `entità` 2:7, `biċċiet` 3:14, `utent` 4:14 |
| `is` | **none, unconditionally** — a flat lowercase rule | *every* term: `skema` 0:23, `gagnaskrá` 0:10, `hlutir` 1:16, `yfirlit` 0:18, `eiginleika` 0:10, `síur` 0:18 |

**`ga` is a third outcome, and the one most easily misread as "no convention".** A naive
per-term count comes out mixed for every term (`clár` 3:5, `scéimre` 9:21, `réad` 11:19,
`comhad` 6:11, `amharc` 3:22) — which looks like carelessness and is not. Condition the
count on whether the **English key** is title-cased and it resolves completely: where the
source is title-cased the `ga` value capitalises **76 against 1**; where the source is prose
it capitalises **0 against 193**. The single apparent exception is not one (`Update register
OAS: ...` has lowercase `register` in the English too, so the `ga` lowercase mirrors it).
So before concluding a locale has no capitalisation convention, re-measure with the source's
own casing as the condition — a mixed per-term split is what a mirroring convention looks
like from the wrong angle.

**`is` is the fourth outcome, and it is the one that genuinely has no list: a flat
lowercase rule.** Every term comes out one-sided lowercase, and — this is what distinguishes
it from `ga` rather than merely resembling it — conditioning on the English key's casing
changes *nothing*. `skema` is 0:9 under title-cased keys and 0:14 under prose keys; `skrá`
0:5 and 0:9. So the four outcomes now seen are: a list of capitalised terms (`sr`, `rm`,
`mt`), mirror the source (`ga`), flat lowercase (`is`), and — still unobserved — flat
uppercase. **Run the conditioned measurement even when the unconditioned one already looks
one-sided**, because it is the only thing that tells `is` apart from `ga`, and they need
opposite handling for every title-cased key in the file.

So carrying `sr`'s list to `rm` would have capitalised `Object` in 63 places against the
bundle's own unanimous practice, and carrying either to `ga` would have overridden the
source's casing in both directions. `mt` adds a further warning: it capitalises `Reġistru`
but lowercases `skema`, the two paired core concepts of the app, so even *within* one
locale the list cannot be inferred from what a term means.

**Measure the PRE-EXISTING half separately from the half you just wrote, per term.** This
is the check that catches your own drift, and on `mt` it did: HEAD capitalised `Fajl`/`Fajls`
mid-sentence 43 times against 0, while the newly written values had lowercased it 24 times
against 4 — a convention broken in 24 places, invisible to every gate, and silently
self-confirmed if you measure the finished bundle as a whole. Whole-bundle counts came out
"CAPITALISED" for `Fajl` either way because the pre-existing majority outvoted the new
values. Split the corpus at `HEAD` and compare the two columns.

**Apply the same split to DECLENSION, not only to casing** — this is what it caught on `is`,
and it is a distinct failure mode. A borrowed noun may be treated as indeclinable by the
bundle while the language would ordinarily inflect it: `skema` appears 25 times in bare form
at HEAD against a single declined `skemanu`, i.e. effectively indeclinable. The values
written in this pass introduced `skemað` 5 times and `skemans` once — grammatically
defensible Icelandic, and inconsistent with the file, which is what §3.5 settles against.
Nothing else catches this: the values are not empty, not identical, not wrong-arity, and
each reads correctly on its own. Two practical notes: tally the forms **per surface form**
rather than per lemma so the split is visible at all, and use `\p{L}` not `\w` when doing
it — `\w` is ASCII-only, so `skema\w*` silently truncates `skemað` to `skema` and hides
exactly the drift you are looking for. That mistake concealed the finding on the first run
here. Both rules can apply inside one value — `rm` writes
`naginas relaziuns cun objects u Datotecas`. `rm` also capitalises the **polite pronoun
and possessive** mid-sentence without exception (`Vus` 13:0, `Voss*` 21:0), the German
`Sie`/`Ihr` model; a sibling app in the same repo lowercases them, so that is a real
choice rather than an accident.

The sharpest case is `sr`, which capitalises the six first-class register concepts
mid-sentence, like proper nouns, and lowercases everything else:

| Capitalised | Lowercase |
| --- | --- |
| `Шема` 33:1 · `Регистар` 30:0 · `Објекат` 62:0 · `Својство` 25:0 · `Датотека` 43:0 · `Извор` 5:1 | `приказ` 0:41 · `ентитет` 0:13 · `ток` 0:25 |

So `Обриши све Објекте у овој Шеми` but `Обриши приказ`. That affects roughly a third of all
values, no other locale here does it, and **no gate can see it** — a wrongly-cased value is
otherwise a perfectly good translation. Get it wrong and you have introduced 300 small
inconsistencies that nothing will ever flag.

How to measure: count capitalised-mid-sentence against lowercase **per term** (the
`(?<=.)(?<!\p{L})` guard keeps sentence-initial positions out of the count). A one-sided
split is a convention to follow; a 1-of-34 outlier is a slip worth normalising while you are
there, since leaving it means explaining later why the rule has exceptions.

The same applies to the conventions already collected in `docs/l10n-ui-translation.md`:
ellipsis spacing (`nb` and `sl` put a space before, `ru` and `bg` do not), dash choice,
whether `%` takes a space, quote glyphs (`da` opens with `”`, `ru` uses guillemets), and
weaker domain-term capitalisation in `da` `sv` `pl`.

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

### 9.2 Review the 16 pre-rule locales

`cs da de el es fi fr hu it nb nl pl pt ru sv uk` were finished before the cognate rule.
Between them they carry several hundred `value === key` entries **nobody has checked** (count
them with `npm run test:l10n:parity -- --strict-identical`), and the gate
cannot judge them because there is no `locales/<loc>.json`.

Per locale: `npm run l10n:status -- <loc>` lists the unjustified ones; audit with
`npm run test:l10n:parity -- --strict-identical`. For each, decide genuine cognate (record
the reason) or filler (translate it). Then write `locales/<loc>.json` and it becomes
enforced from that moment — no code change needed, the gate keys on the file existing.

Expect a high legitimate rate in some (`nl` renders `Bewaartermijn` and
`AVG / Verwerkingsregister` unchanged — Dutch words in a Dutch bundle) and the opposite in
others; this is the audit that turned up 46 placeholders in `tr`. The register verdicts for
these 16 are already recorded, so step 2 is done for them; a detector is still needed for
`selfcheck` to check register.

---

## 10. Source-side defects still open

These are **source** fixes, not translation work. Each one costs 37 bundle entries or
renders wrongly in every locale.

- **`test:l10n` IS CURRENTLY RED, AND NOT BECAUSE OF l10n WORK.** This is the §6.15
  situation, already live: a `development` merge (`28d24aa08`) de-Dutchified the AVG source
  strings and added flow strings, leaving **17 keys used in `src/` but missing from
  `en.js`**. Verified pre-existing — the same failure reproduces in a clean worktree at
  HEAD with no l10n changes present, and neither `en.js` nor `src/` has been touched since.
  Eleven of the 17 are English replacements for Dutch-keyed strings that are still in
  `en.js` and now unused: `Inzage (Art 15)` → `Access (Art 15)`, `Inzage results` →
  `Access results`, `Verantwoording` → `Accountability`, `AVG / Verwerkingsregister` →
  `GDPR / AVG processing register`, `Generate the verantwoordingsdocument` → `Generate the
  accountability document`, `Bewaartermijn`/`Rechtsgrond` → `Retention period`/`Legal
  basis`, plus the two `verwerkingsactiviteit` sentences and the `Locate every object…`
  blurb. The other six are new: `App`, `New flow`, `Schedule`, `Trigger`, `Enabled, but has
  no owner — it will not start`, and the flows-list description.

  The fix is a **rename**, not an add, for those eleven — `l10n-ai.js rename` carries all
  37 bundles at once, so every finished locale's existing translation survives and parity
  never breaks. Do the renames first, then `test:l10n:write` for the six genuinely new
  keys, then translate those six across the finished set (§6.15). Note `rename` does not
  rewrite call sites, but here the call sites are already the *new* strings — it is `en.js`
  that is behind — so no `src/` edit is needed for the renames. Its own commit, per §3.10.

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
  cognate-justification gates.

The whole of `scripts/l10n/` is worth porting, not just the library — openconnector has the
same 37-bundle problem and none of the tooling.
