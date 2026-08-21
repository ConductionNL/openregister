# l10n audit findings — what past locale passes actually found

Evidence from locale passes already done. **Nothing here is a rule.** The rules are in
`CLAUDE.md` (short) and `docs/l10n-workflow.md` (the runbook); this file exists so those
two do not have to carry the reasoning behind them.

Read it when you are about to audit a locale and want to know where the defects usually
are, or when a measurement is telling you something surprising and you want to know
whether someone has already met that surprise.

## Defect rates, and what predicts them

| Locale | Defects | Of | Shape |
| --- | --- | --- | --- |
| `is` | 235 | 1052 pre-existing (22%) | wrong gender, wrong case, malformed compounds, garbled words, foreign stems, wrong senses |
| `ca` | 128 | 2052 (6.2%) | no dominant term; biggest class 35 keys, eleven classes of 2–11 |
| `cs` | 113 | 2052 (5.5%) | terminology drift and internal inconsistency; zero garbled words, zero foreign stems, zero agreement failures, zero bad arrays |
| `lb` | 77 | — | 60 of them ONE orthographic rule (Eifeler Regel) |
| `sk` | 57 | 2052 (2.8%) | 37 of the 57 were a single term |

**The rate tracks how healthy the locale is upstream, not how long it went un-audited.**
`cs` predates all of this tooling and still came in at 5.5% against `is`'s 22%.

**Counting competing renderings per English term is the highest-yield first move** — about
70 of `cs`'s 113 and 37 of `sk`'s 57, with no knowledge of the language required. Do that
count before reading values. `npm run l10n:termdrift -- <loc>`.

**A `corrections` count of 0 means "unverified", not "clean".** `sk` had a measured
register, a detector, a reviewed cognate set and an empty `corrections`, and the audit
still found 57 real defects including two semantic reversals invisible to every gate. A 0
does not imply a quarter of the file is waiting either. Read it as "nobody looked".

**Mechanical morphology checks almost never pay** — 4 of 239 on `is`, ~0 of 113 on `cs`.
The one exception is a deterministic sandhi rule; see below.

## Collisions the user can see on one screen

`ca`'s two worst defects were byte-identical renderings sitting next to each other:
`Settings`/`Configuration` both rendering `Configuració` as sibling tab labels in three
dialogs, and `Logs`/`Registers` both rendering `Registres` as the two tabs of one tab bar.

Hunt this first in any locale whose language collapses two of the app's nouns: grep the
`tabs:` arrays and the paired empty states. A collision the user can see is a defect no
amount of "both words are correct Catalan" excuses.

Still open, same class: `es` and `pt` render `Logs` and `Registers` identically
(`Registros` / `Registos`), and those two blocks appear together in `RegistersSideBar`.

## Sandhi: ask the question, expect a different answer each time

**`lb` is the counter-example to "budget for terminology, not grammar".** Its terminology
was healthy and 60 of its 77 corrections were one orthographic rule — the Eifeler Regel,
where word-final `-n` deletes before any consonant but `n d t z h`. Obligatory, fires
several times per sentence, invisible to every gate, broken in *both* directions. It found
44 further violations in the half written *during* the pass, so split at HEAD and re-run it
on your own work. Method: `docs/l10n-workflow.md` §8.11.

The same shape applies to French elision/liaison, Irish initial mutation, Italian `lo`/`il`.

**But `mk` is where the question was asked and came out no.** Macedonian obligatorily
doubles a definite direct object with an accusative clitic (`Избриши ГО објектот`), which
looks exactly like the Eifeler Regel and is not the same shape: the trigger is *semantic*
(definiteness) rather than orthographic, and the clitic's position depends on clause type.
The check scored 0 of 5. What paid on `mk` instead was capitalisation — 118 occurrences
over 105 values, the dominant class of that pass.

## Measuring a bundle's own practice

**Aggregate by WORD CLASS, not only per lemma.** On `lb`, believing otherwise cost a whole
round: a lemma occurring in only one environment carries no information, so `Lueden`
(always with `-n`) and `Deele` (always without) both read as "consistent", and 26 values
were excused on a "16:0, no counter-example" count that was really one copy-pasted phrase.
By word class the family does both, 118 to 108. **A uniform lemma is evidence of one
decision copied, not of a rule.**

**A capitalisation ratio conflates two populations, and the confound invents work.**
Measure mid-sentence casing over **prose only** (English key ≥6 words and not Title Case),
and separately ask whether short labels *mirror* the key's Title Case. On `sq` the naive
scan reported 25–35% against a family rate near zero — a tidy ~110-value defect class that
does not exist: prose is 0-of-177, and every hit was a Title-Cased heading correctly
following its source. If a defect class is large, uniform and concentrated in short values,
you are measuring the source, not the translation.

**The conditioned measurement can still come back real, and on `mk` it did.** Restricted to
prose keys the bundle capitalised 41 against 146 where the sibling frontends run 1:273 and
core 2:~480, and the same rule was broken again in headings (65:507 against core's 7:122).
What tells that apart from `sq`'s phantom: it does not evaporate under the restriction, and
the bundle is *internally inconsistent* — `објект` is 0:26 lowercase while `Датотека` is
20:0 capitalised. So there was no convention to follow, unlike `sr`, which capitalised its
six first-class concepts one-sidedly across the whole bundle.

**Three ways a casing measurement misleads, all met on `mk`:**

- A stem alternation written without the `i` flag matches only the lowercase form, so every
  capitalised occurrence — the entire thing being measured — is invisible, and every term
  reports a clean 0-up.
- `(?<=.)` excludes the value's first word but **not a later sentence's**, so
  `… пребарување. Објектите се …` scores as a mid-sentence capital. Three first-cut hits
  were that, and each would have been "corrected" into an error.
- An opening parenthesis, a leading emoji and deliberate all-caps each license a capital the
  same way a sentence start does — 14 of `mk`'s 132 raw hits.

Review the word list before applying any casing fix.

## Detectors

**An ACRONYM can be a homograph of a register marker, and case is the only thing separating
them.** `ВИ` is Macedonian for *AI* (вештачка интелигенција) and this bundle uses it
(`ВИ-агент`, `ВИ-функции`), while `ви` is the formal dative clitic. A detector that folds
case scores the app's AI vocabulary as deferential address. `detectors/mk.js` therefore
consumes the all-caps form in `fold()` before lowercasing. The hyphen goes in the **left**
guard only: Macedonian attaches acronyms to the following noun with a hyphen, so the
acronym must still match with a hyphen after it.

That is the `lb` `dir`/`Dir` situation from the other end — `lb` had to preserve case
throughout, `mk` needs it for one token and can spend it up front.

**A marker guard must know where the target puts a MORPHEME boundary, not just what is a
letter.** `(?<!\p{L})` is wrong for Albanian, which attaches the definite ending to acronyms
after a hyphen (`UUID-je`, `Token-i`, `PHP-ja`), so it matched an inflectional ending as the
2sg copula. `detectors/sq.js` uses `(?<![\p{L}-])` — but must NOT guard the apostrophe,
since `t'ju` is formal and `s'ke` informal. Catalan needed the interpunct *inside* the token
class: same question, opposite answer.

**Thin core coverage is more dangerous than none.** `rm` and `mt` had zero catalogues, so
the scan threw and the step could not run by accident. `lb` had one catalogue with 72
values, so it *succeeded* — and would have reported a register verdict computed from **0
markers**, since those 72 values contain no address form. Measure the marker count, not the
catalogue count. `bs` (55 values) is the same trap.

**A functional field described in prose is not a functional field.** The `mk` pass wrote the
whole `pluralBoundary` rationale into `pluralNote` and never set `pluralBoundary` itself.
Third instance of this after `pluralOrder` and `spellAllow`, but the first to fail loudly,
because `runtime-check` treats an unacknowledged boundary disagreement as fatal. The two
earlier ones failed in the safe direction and went unnoticed for passes. Set the field, then
re-run the check that reads it.

## Button conventions

**`sq`'s LENGTH-GRADED convention replicated on `mk`**, which is what makes it a shape worth
checking rather than one locale's quirk — and the crossover landed in the same place, ~40
characters. Core `mk` runs 128:1 for the 2sg imperative at ≤14 characters and 6:12 the other
way at 80+.

The `Select`/`Choose`/`Enter` prompt override sits on top of the gradient in both locales,
and it is **lexically bounded rather than "any prompt"**: `Search` goes the other way and is
not close (core `mk` 61:1 for 2sg). A dropdown you pick from and a field you type into
address the user; a toolbar button is something you press.

## Before "fixing" an outlier, check core AND the call site

Core `cs` overturned four candidate corrections, one of which was the only value in its
family that actually matched core. On `sk` the two together overturned **ten** — more than
any single class that pass corrected. Core `sk` itself collapses `Refresh`/`Restore` onto
`Obnoviť`, ships `Prvé`/`Posledné`/`Predchádzajúce` and bare `Hľadať` verbatim, and uses
`Riešiteľ` for *Handler* because the field holds a **person**. Full list in
`docs/l10n-workflow.md` §6.9.

**The reports have their own blind spots.** `l10n:spell` was splitting every Catalan `l·l`
word in half and reporting the halves as misspellings — a locale's orthography can defeat
the tooling silently. Read a report for tooling failure before you read it for defects.
