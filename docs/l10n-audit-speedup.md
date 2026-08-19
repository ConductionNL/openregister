<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Making a locale audit fast — setup and method

The `sk` audit (57 corrections over 2052 values) took a whole session, and most of
that time went into two things that did not need to be slow: **reading all 2052
values in sequence**, and **forming candidate corrections that were then killed**.
This document is the fix, and it is written so a second machine can reproduce it.

Read `docs/l10n-workflow.md` §6.9 for the audit method itself. This is about the
tooling that makes §6.9 cheap, plus one honest measurement of what each tool
actually catches.

---

## 1. One-time machine setup

Only the spell pass needs anything installed. Everything else is plain Node and
already works from a fresh clone.

```bash
# 1. hunspell itself
sudo pacman -S hunspell            # Arch / CachyOS
# sudo apt install hunspell        # Debian / Ubuntu
# brew install hunspell            # macOS

# 2. the dictionaries — from the repo, NOT from your distro
npm run l10n:fetchdicts            # all 30 available locales
npm run l10n:fetchdicts -- sk cs   # or just the ones you need
```

They land in `scripts/l10n/dicts/<loc>.{aff,dic}`, which is gitignored. Re-running
skips what is already there.

### Do not install distro dictionary packages

It is the obvious move and it is the wrong one:

- **Arch/CachyOS official repos carry dictionaries for only ~10 of our 36
  locales.** `sk`, `cs`, `sl`, `hr`, `bg`, `lt`, `lv`, `et`, `is`, `ga`, `mt` and
  every Nordic language are absent. The 40 `hunspell-*` packages are mostly
  regional Spanish variants.
- Package names differ on every distro, so "install the dictionaries" is not a
  reproducible instruction.

`scripts/l10n/fetch-dicts.js` pulls from LibreOffice's dictionary repository
instead: **30 of our 36 locales, no root, identical on every machine.**

### Six locales have no dictionary at all

`fi` `ga` `lb` `mk` `mt` `rm`. Finnish needs Voikko (morphological, not hunspell);
the other five are low-resource. This is the unlucky part — **`ga`, `mt` and
`is`-like locales are exactly where the garbled-word class concentrates**, so treat
a missing dictionary as a known gap, never as evidence the locale is clean.

---

## 2. The four reports, and what each one is measured to catch

Run them in this order. The numbers below are measured against `sk`'s 57 known
corrections and `is`'s pre-audit bundle, not estimated.

| Order | Command | Measured yield |
| --- | --- | --- |
| 1 | `npm run l10n:corediff -- <loc>` | pre-empts **6 of 6** false candidates |
| 2 | `npm run l10n:termdrift -- <loc>` | surfaces the term behind **37 of 57** |
| 3 | `npm run l10n:spell -- <loc> --suggest` | **1 of 57** on `sk`; **5 of 5** garbled words on `is` |
| 4 | the dangling-preposition scan (§6.9) | **7 of 57** |

Together these reach roughly **48 of `sk`'s 57** from a few hundred lines of report
instead of 2052 values of prose. The remaining ~9 still need reading — the
subject/object reversal and the swapped tab pair are not mechanically findable.

### 2.1 `core-diff.js` — run this FIRST, before reading anything

Prints every key that exists in both the bundle and Nextcloud core, split into
**AGREE** and **DISAGREE**, with each core rendering and how many catalogues use it.

This is the highest-value tool in the set, and not because it finds defects — it
**stops you inventing them**. The `sk` pass checked core only *after* forming
candidates, and core then overturned six: `Refresh`/`Restore` (core collapses them
onto `Obnoviť` exactly as the bundle does), `First`/`Last`/`Previous` and bare
`Search` (core ships the bundle's exact wording). Every one looked like a textbook
defect on grep evidence. The `cs` pass lost four the same way.

All six sit in this report's **AGREE** list. Read that list as *never question
these*, and the false-candidate round disappears. On `sk`, `Delete → Zmazať` also
appears at the top of DISAGREE, which is the single biggest terminology decision in
the pass — it would have surfaced in the first minute instead of an hour in.

**A disagreement is evidence, not a verdict.** §3.5 says the bundle usually wins on
lexicon where it differs from core, and `hr` deliberately keeps `lozinka` over
core's `zaporka`. `sk` shows 88 agree / 19 disagree; most of the 19 are fine.

### 2.2 `termdrift.js` — the highest-recall defect finder

Indexes every English content word to the keys containing it, clusters the target
values by stem, and reports where one rendering dominates and a minority carries
none of it. **The bundle saying the same English thing two ways.**

§6.9 already said to count competing renderings first, and it produced ~70 of `cs`'s
113 and 41 of `is`'s. But all three passes did that counting **by hand against a
guessed term list** — `register`, `schema`, `file`, `audit`… On `sk` the term that
had actually drifted was only found because `audit` happened to be on the guess
list. This tool removes the guess: it counts all of them.

On the pre-audit `sk` bundle it prints 42 word-entries, one of which is:

```
  "trail" — 15/16 keys use "zazna-", 1 do not:
      "Audit trail #{id}"    "Auditná stopa #{id}"
```

That one line is the entry point to 37 of the 57 corrections.

**The majority is not automatically right.** That single minority rendering is the
one the owner chose, and it became the convention for the other 36 keys. The tool
surfaces the *split*; you still decide which side wins, via core and the call site.

Two measured blind spots, both of which cost `sk` corrections that needed reading:

- **Inflection-level splits.** `Poradie fasiet` vs `Poradie fasety` share the stem
  `faset`, so a stem clusterer cannot see them.
- **Word-order splits.** `špecifikáciu API` vs `API špecifikáciu` have identical
  stems.

### 2.3 `spell.js` — for wrong-language contamination

The class it is really for is a **neighbouring language's stem sitting in a
committed bundle**, which a reader's eye slides straight over. Against `is` as it
was before its audit, it catches all five of the words that pass found by reading:

```
  Stav             -> Staf            (Slavic, in an Icelandic bundle)
  skrivaðgang      -> skrúðganga      (Danish/Norwegian stem for skrifaðgang)
  levranir         -> leiran
  Misheppnaðst     -> Misheppnast
  Búningaaðgerðir  -> Búningagerð
```

On `sk` it catches the one typo, `strategie` (the Czech spelling), and suggests
`stratégie` — the exact correction.

**It will not adjudicate derived or technical vocabulary.** Measured: the Slovak
dictionary rejects **both** `auditný` and `audítny`, so the 35-key adjective
misspelling that dominated the `sk` pass is invisible here. That argument had to be
made from Slovak morphology plus the bundle's own forms, and no dictionary would
have helped.

**Noise, and the one-time allowlist.** A raw sweep flags 131 of 2430 words on `sk`
(5%) but **688 of 2420 on `is`** (28%) — richly inflected, thinly covered languages
are much noisier. Almost all noise is product names and real domain vocabulary no
general dictionary carries (`webhook`, `token`, `vektorizácia`, `faseta`, `úsek`,
`nástenka`). Put those in `spellAllow` in `scripts/l10n/locales/<loc>.json` **once**,
and the report stays short for every later pass. Budget the first run of a locale
for building that list.

### 2.4 The dangling-preposition scan

Not yet its own script; the §6.9 regex sweep. Found 7 of `sk`'s 57 — `Detected At`
→ `Zistené o` and five siblings, all `<th>` column headers leaving a preposition
without its object. Fix onto the bundle's own `Deleted Date` → `Dátum odstránenia`
pattern.

---

## 3. What was tried and rejected

**A cross-locale outlier report** — cluster all 36 locales' values per key by shared
character n-grams and flag a locale sharing nothing with the consensus. It was built,
measured, and **deleted**: recall **1 of 57**, precision **1 of 65**. It found
`Breaking change` and nothing else, because the terminology that actually drifts is
app-specific and has no cross-locale consensus to be an outlier against. Shipping a
1/65 tool into a runbook the next pass trusts is worse than not having it.

The useful cross-locale check is the one that already exists: `node
scripts/l10n-ai.js get <key>`, used **on demand** once you have a suspicion. That is
how `Breaking change` (17 of 20 locales say "incompatible") and the swapped
`Uses`/`Used by` pair were settled. Manual and targeted beats scanned and broad here.

**Mechanical morphology checks** (gender/case agreement lexicons) stay rejected, as
§6.9 already says: 4 of 239 on `is`, ~0 of 113 on `cs`, and not written for `sk`.

---

## 4. Process changes, not tooling

### 4.1 Run the read-through as a SUBAGENT FAN-OUT

Not "read faster" — **use the Agent tool**. Concretely:

1. Slice the EN→target listing into ~4 chunks of ~500 keys, into the scratchpad.
2. Spawn **one subagent per chunk, in a single message** so they run concurrently.
3. Hand each one the same **shared context**: the `core-diff` **AGREE** list, the
   `termdrift` output, the collision list, and the locale's measured register and
   button convention (§7.2/§7.3). Skipping this is the main way a fan-out wastes
   money — a reader without the AGREE list re-derives the false candidates core has
   already killed, and one without the button convention reports every infinitive
   button as a register slip.
4. Require **candidates with call-site evidence, never verdicts**, as structured
   output: key, current value, what is wrong, suggested fix, confidence.
5. **Adjudicate centrally**, applying the core check and the call-site check to every
   candidate before it enters a patch.

**This raises recall, not just speed — which is the part I did not expect.** The `sk`
audit was committed as complete at 57 corrections. A second pass over the *same*
bundle in a fresh subagent context found **five more**, four of them substantive: a
nominative plural where the genitive singular belonged (`Konfigurácia objektu
polia`), a noun where the button convention takes an infinitive (`Draft denial`), one
formal-2pl item in a six-item list of infinitives, and the bundle's word for
*session* used to mean *relation*. The first pass had also claimed "zero wrong case in
`sk`", which was false. **`sk` is 62, not 57.** A single sequential reader has a
recall ceiling that care does not remove: what survives a first read is exactly what a
tired eye normalises.

Corollary: **do not read a completed audit as proof a locale is clean.** Record the
count, never a verdict.

### 4.2 Cheap models generate candidates; they do not decide

Spotting an odd word in a list is recall. Adjudication is the opposite, and it is
where the errors are: on `sk`, **eleven of about thirty candidates were wrong**, each
killed only by a core lookup or a call-site read.

**Measured, on the pre-audit `sk` bundle.** A Haiku-class reader returned three
findings: one real (`Loading organisations...`, at *medium* confidence) and **two at
*high* confidence that were both wrong** — it wanted `nemajú` where Slovak requires
the 3sg `nemá` after a genitive-plural numeral, and the masculine-*animate* `obnovení`
where the inanimate `obnovené` is correct. Applying either would have written a **new**
error into correct Slovak, and nothing in this repo could have caught it. Note the
inversion: its only correct finding was its least confident one, so its own confidence
field could not have been used as a filter.

So: cheap models are usable for the fan-out, where recall is the job and every output
is reviewed. **Never let a subagent's suggestion reach a patch unreviewed.**

### 4.3 Give subagents a read boundary

A subagent that touches the repo gets `CLAUDE.md` and `docs/l10n-workflow.md`
auto-attached, and §6.9 names the actual `sk` findings and trap list. That is harmless
in a real pass but **fatal to any attempt to measure a reader's quality** against a
locale that has already been audited: point such a run at a self-contained scratchpad
copy and forbid the checkout explicitly. One model disclosed the injection unprompted,
which is the only reason a flattering, meaningless score was not recorded here as fact.

### 4.4 Do not skip these, however tempting

- the call-site check and the core check — they overturned eleven between them;
- the 16-locale regression loop at the end — cheap, and it is the safety net;
- recording an escalated decision that came back "change nothing" — an unrecorded
  "left alone" is indistinguishable from "never looked", which is the whole reason
  a `corrections` count of 0 cannot be trusted.

---

## 5. Quick reference

```bash
# once per machine
sudo pacman -S hunspell && npm run l10n:fetchdicts

# per locale, in this order
npm run l10n:corediff  -- <loc>            # read AGREE first: never question these
npm run l10n:termdrift -- <loc>            # the split list; majority is not always right
npm run l10n:spell     -- <loc> --suggest  # wrong-language stems and typos
npm run l10n:status    -- <loc>

# then read what is left, fix through apply.js, and verify
npm run l10n:apply     -- <loc> patch.json --apply --allow-replace='k1||k2'
npm run l10n:selfcheck -- <loc>
npm run l10n:runtime   -- <loc>
npm run l10n:gatetest  -- <loc>
npm run check:specs && npm run test:l10n:parity && npm run format
```

All four reports are **reading aids and never fail** — they exit 0 whatever they
find, so none of them can gate a build or be mistaken for one.
