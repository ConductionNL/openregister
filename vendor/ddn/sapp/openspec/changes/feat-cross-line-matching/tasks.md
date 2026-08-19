## 1. Pre-flight

- [x] 1.1 Capture failure shape from fixture fictieve_brief_anonimisering.pdf (oid=4 blocks 42/43: `…dat op 14 mei ` / `2026 heeft…`, F2|11, gap 12.48pt)
- [x] 1.2 Confirm Phases 1–3 cannot reach it (Phase 3 grouper is same-Y only)

## 2. Phase 4 — cross-line matching

- [x] 2.1 Add `groupBlocksIntoLinePairs(array $blocks, array &$stats): array` — font|size buckets, Y-desc/X-asc sort, same-Y runs collapsed to visual lines, consecutive lines paired on gap `(0.5, 2.0×size]` + margin delta `≤ 1.5×size`; pair = (last block of top line, first block of bottom line)
- [x] 2.2 Add `applyCrossLineReplacements(...)` — concat match view with synthetic-space wrap model (D3), claimed-range tracking (D4), v1 start-op-last/end-op-first restriction, reverse-offset edit application
- [x] 2.3 Extend `buildCrossBlockEdit` with `$preserveInterBlockBytes` (D5); Phase 3 call sites unchanged
- [x] 2.4 Wire Phase 4 after the Phase 3 pass in `replaceInContentStream`
- [x] 2.5 Diagnostic counters: `cross_line_pairs_built`, `cross_line_matches`, `cross_line_skipped_multi_op_block`

## 3. Verification

- [x] 3.1 Full production substitution map (34 needles, longest-first): `unmatched_needles: []`, `cross_line_matches: 1`
- [x] 3.2 Overlap guard: `4 mei 2026` count stays 1 alongside `14 mei 2026`
- [x] 3.3 smalot re-extraction of output parses and shows the placeholder at the wrap point; OpenRegister strict validation passes
- [ ] 3.4 End-to-end through DocuDesk anonymise flow on the dev environment
- [ ] 3.5 Commit on a feature branch + push to Codeberg; update OpenRegister composer pin
