<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Locale audit — rules that make it fast

Judgement rules live in `docs/l10n-workflow.md` §6.9. This is only the speed-up list.

## Setup, once per machine

```bash
sudo pacman -S hunspell        # or apt/brew
npm run l10n:fetchdicts        # LibreOffice dictionaries, no root, 30 of 36 locales
```

`fi ga lb mk mt rm` have no dictionary. That is a gap, not a clean bill of health.

## Rules

1. **Run the three reports before reading anything.** They reach ~48 of `sk`'s 62.

   ```bash
   npm run l10n:corediff  -- <loc>
   npm run l10n:termdrift -- <loc>
   npm run l10n:spell     -- <loc> --suggest
   ```

2. **`corediff` AGREE = never question those values.** It killed 6 of 6 bad candidates on
   `sk`. `DISAGREE` is the worklist, not a fix list — the bundle usually wins on lexicon.

3. **Never hand-count terms.** `termdrift` does it over every word; hand-counting used a
   guessed list and nearly missed the term behind 37 of `sk`'s corrections.

4. **The minority side of a split is not automatically the defect.** On `sk` it was correct.

5. **Never write morphology checkers.** 4 of 239 on `is`, ~0 of 113 on `cs`.

6. **Read what's left as a subagent fan-out**, not sequentially — ~4 chunks, one subagent
   each, spawned in one message. A second pass on `sk` found 5 defects the first missed, so
   this raises recall as well as speed.

7. **Give every subagent the shared context**: the AGREE list, `termdrift` output, collision
   list, and the locale's register + button convention. Without it they re-derive dead
   candidates and flag every infinitive button as a register slip.

8. **Subagents return candidates, never verdicts.** Cheap models are fine for generating
   them. A Haiku-class reader gave 1 real finding and 2 confidently wrong ones that would
   have written new errors into correct Slovak. Its confidence field was anti-correlated, so
   don't filter on it.

9. **Adjudicate every candidate against core and the call site.** 11 of ~30 `sk` candidates
   died there. Skipping this is how a fast audit ships regressions no gate can catch.

10. **Give subagents a read boundary** if you are measuring their quality: touching the repo
    auto-attaches `CLAUDE.md` and the runbook, and §6.9 names the `sk` answers.

11. **A finished audit is not proof a locale is clean.** Record the count, not a verdict.

12. **Read the spell report for tooling failure before reading it for defects.** A cluster of
    implausible short words sharing a stem with a real one means the tokeniser split something,
    not that the locale is garbled. `ca` produced `lecció`, `lel`, `paral`, `lada`,
    `laboratives` — all halves of `col·lecció`/`paral·lel`/`instal·lada`, because U+00B7 was
    missing from the token class. Fixed, but the class generalises to any orthography with
    word-internal punctuation.

13. **Grep the `tabs:` arrays for byte-identical collisions.** The two worst `ca` defects were
    two pairs of sibling tab labels rendering the same string — visible to any user, invisible
    to every gate, and not findable by reading values one at a time. Cheap:
    `grep -rn "tabs:" src/ -A4` and check each pair against the bundle.

## Don't rebuild this

A cross-locale outlier scanner (cluster all 36 locales per key, flag the odd one out) scored
recall 1 of 57, precision 1 of 65. Deleted. Use `node scripts/l10n-ai.js get <key>` on demand
instead.
