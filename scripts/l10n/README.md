# `scripts/l10n/` — frontend translation tooling

Everything for `l10n/*.js` (the **frontend** catalogue, read by `OC.L10N.register` →
`t()` / `n()`). The backend `l10n/*.json` set is a separate concern with a separate
consumer (PHP `IL10N`) and no scanner yet.

**Read `docs/l10n-workflow.md` first.** It is the runbook: the pass in order, what to
do when any of these scripts refuses, and the traps catalogue. This file is the
reference for the tooling itself — what lives where, and what each gate rejects.

Then **`docs/l10n-ui-translation.md`** for the parts that are not mechanical:
measuring register against Nextcloud core instead of assuming it, why harvested
values must be checked at their call site, the plural boundaries per language, and
the conventions already established per locale.

## Layout

| Path | What |
| --- | --- |
| `lib.js` | The one shared library. Catalogue parsing/serializing/extraction, plus the per-locale-pass helpers. |
| `batch.js` | Read-only status and worklist for a locale. |
| `apply.js` | The **only** writer. Six gates; refuses the whole patch rather than landing half of it. |
| `harvest.js` | Candidate values from core and sibling apps. Candidates, never answers. |
| `patchcheck.js` | Runs a locale's register detector over a patch **before** it is applied. |
| `selfcheck.js` | Full pre-commit verification for one locale. |
| `runtime-check.mjs` | Drives the real `@nextcloud/l10n` against a real bundle. |
| `gate-negative-test.js` | Proves `test:l10n:parity` really fails when one locale loses a key. Snapshots, breaks, asserts, restores. |
| `script-coverage.js` | Two jobs. For a non-Latin locale (`bg sr mk be uk ru el`) the script sweep that replaces §5 step 8's English-word scan. For **every** locale including Latin ones, the homoglyph check: a single word mixing two scripts. Reading aid, never a gate. |
| `core-diff.js` | Bundle vs Nextcloud core, split AGREE / DISAGREE. **First thing in an audit** — its AGREE list is what you must not "fix". Reading aid, never a gate. |
| `termdrift.js` | English words this bundle renders two ways. The §6.9 term count over *every* word rather than a guessed list. Reading aid, never a gate. |
| `casing.js` | Mid-sentence capitalisation per term, conditioned on the English key, against the sibling-frontend and core baselines. The §8.10 measurement, which five passes did by hand. `--mine` restricts it to what this working tree changed. Reading aid, never a gate. |
| `spell.js` | Words absent from a hunspell dictionary — wrong-language stems and typos. Needs `fetch-dicts.js`. Reading aid, never a gate. |
| `fetch-dicts.js` | Pulls hunspell dictionaries into `dicts/` (gitignored). One-time per machine; 30 of 36 locales have one. |
| `detectors/<loc>.js` | Per-locale register detector: closed word lists + must-fire / must-not-fire controls. |
| `locales/<loc>.json` | Per-locale record: measured register, justified cognates, audited corrections. |

`lib.js` was `scripts/lib/l10n.js` until 2026-08-14. It moved here so there is one
l10n folder and one library in it; `scripts/lib/` no longer exists. **openconnector
vendors a copy and still has it at the old path** — move it when you next sync.

## A locale pass, end to end

```bash
node scripts/l10n/batch.js status  lv          # what is left
node scripts/l10n/batch.js absent  lv > /tmp/lv-todo.json

# measure the register against core, then build detectors/lv.js and write
# locales/lv.json with {"register": "formal"|"informal", "cognates": {}, "corrections": {}}
node scripts/l10n/detectors/lv.js              # runs its own controls + scans core

node scripts/l10n/harvest.js lv /tmp/lv-todo.json   # ~2-7% hit rate; verify every hit

# translate in ~200-key batches
node scripts/l10n/patchcheck.js lv patch-1.json     # register slip? catch it now
node scripts/l10n/apply.js      lv patch-1.json     # dry run
node scripts/l10n/apply.js      lv patch-1.json --apply

node scripts/l10n/selfcheck.js     lv          # 16 assertions, must be all-pass
node scripts/l10n/runtime-check.mjs lv         # what actually renders
node scripts/l10n/script-coverage.js lv        # homoglyphs for any locale; script sweep if non-Latin
npm run check:l10n && npm run test:l10n:parity

# prove the gate really fails when this bundle loses a key
node scripts/l10n/gate-negative-test.js lv
```

Then bump the counts in `CLAUDE.md`, add the locale's conventions to
`docs/l10n-ui-translation.md`, tick the locale off the order-of-work list in
`docs/l10n-workflow.md`, and commit. One commit per language.

## The two rules the tooling enforces for you

**Never write a value equal to its key** — in any locale except `en`. An absent key
falls back to the English source and stays visibly untranslated to every tool; a
value equal to its key renders the same characters while being indistinguishable
from finished work, so nobody ever revisits it.

The exception is a genuine cognate (`CSV`, `PDF`, `RBAC`, `Schema` in Lithuanian,
`Flows` in nl/de/da), which is real finished work and must keep parity. Those are
**written out against a recorded justification** in `locales/<loc>.json`. `apply.js`
refuses an identical value without one, and `test:l10n:parity` fails a locale whose
identical values are not all justified — so the record is enforced, not decorative.

**Plural arrays must match the locale's own `nplurals`.** An array shorter than the
form index the runtime asks for renders **blank**, and it is the one l10n defect you
cannot see by reading the file. Languages that share a form count do not share
boundaries: `hr` and `lt` are both `nplurals=3` and disagree about where forms
switch, so an array copied between them is wrong for counts 5–9.

## Cognate enforcement is opt-in per locale, on purpose

Sixteen locales were finished before the cognate rule existed and carry ~400
identical values nobody has reviewed; `cs` is the first of them to be reviewed, so
fifteen are left and ~375 identical values with them. Failing CI on those would report
history as a regression, and some are legitimate — `nl` renders `Bewaartermijn` and
`AVG / Verwerkingsregister` unchanged because those are Dutch words in a Dutch
bundle. So enforcement keys on the existence of `locales/<loc>.json`: the twenty-one
recorded locales (`tr ca et hr lt lv ro sk sl bg sr rm ga mt is cs lb sq mk be bs`) are held to it, the rest are
**reported** as unreviewed by both
`test:l10n:parity` and `runtime-check.mjs`. Add a `locales/<loc>.json` as each old
locale gets reviewed and it becomes enforced from that moment.

## Two traps in the verification scripts

Both of these were live bugs in these scripts, caught by running them across all 21
finished locales instead of just the one being worked on.

**1. A `SKIP` or `NOTE` is not a bug to tighten away.** "This value is identical to
English" and "this plural rendered the English source" are the symptoms of the two
worst defects this tooling exists to catch — *and* of a perfectly legitimate cognate.
Dutch `register`/`registers` really is the English word; Italian `email` is
invariable, so `1 email` is correct Italian. Nothing automated can tell those apart,
so the justification record decides: a locale with `locales/<loc>.json` is held to it
and an unrecorded English rendering fails, while a locale without one gets a note for
a human. If you hit a NOTE, either record the reason or translate the value — do not
narrow the check until it stops firing.

**2. A locale that keeps `{plural}` is not broken.** `es` keeps it in all five keys
and `ca` in four, because their plural genuinely is `+s` (`fitxer` → `fitxers`). Any
assertion about those keys must branch on whether the value still carries the
placeholder:

- keeps it → the value **must** vary with the count; asserting count-stability is
  exactly backwards.
- drops it → the value **cannot** vary, and a literal `{plural}` would reach the user.

Three assertions inherited from the `hr`/`lt` passes were wrong for `ca` on this
point: count-stability, a blanket no-trailing-`s` rule (which flagged the correct
slash form `esquema/esquemes`), and an unconditional no-`{plural}`-residue rule.

## One thing about the runtime that is easy to get wrong

`register(app, bundle)` **ignores** a plural function passed as a third argument and
installs the library's own per-language `getPlural`, which reads `getLanguage()`. So
the file's `plural=` expression governs the arity gate, while the **library** governs
which element renders. A harness that assumes otherwise silently reads the wrong
form — which happened, and briefly made three correct Slavic arrays look wrong.
`runtime-check.mjs` calls `unregister()` and `setLanguage(loc)` first for this reason.

When the two **disagree**, `runtime-check.mjs` classifies the disagreement rather than
assuming one shape, because the remedies are opposite. A *permutation* disagreement
partitions the counts identically and only labels the parts differently, so reordering the
arrays fixes it completely — that is `lv`, acknowledged with `pluralOrder: "library"`. A
*boundary* disagreement puts the lines in different places, so **no reordering can help**;
you choose which counts to be correct for and record the residue with
`pluralBoundary: "library"` — that is `is` and `mk`, in opposite directions. Telling someone
to reorder the arrays in the second case is wrong advice, which is why the two are separate
fields. See `docs/l10n-workflow.md` §6.7.
