# Retrofit — computed-fields (extend existing capability)

Describes the observed behavior of the four static-analysis methods on `ComputedFieldHandler` that implement circular-dependency detection. The existing `computed-fields` spec specifies the **behavior** of cycle detection ("Circular Dependency Detection" requirement) but does not describe **how** the implementation derives the dependency graph or detects cycles. These four methods were originally triaged DROP from `object-lifecycle#REQ-004` (chunked bulk processing) because they belong to computed-fields, not bulk save. This change retroactively specifies them as 4 new REQs in the computed-fields capability.

## Affected code units
- lib/Service/Object/SaveObject/ComputedFieldHandler.php (4 methods)
  - `detectCircularDependencies()` — public entry point; builds the computed-only dependency graph and walks it for cycles
  - `extractTwigVariables()` — pulls candidate identifiers out of `{{ ... }}` / `{% ... %}` blocks via regex
  - `dfsForCycles()` — depth-first traversal helper that records back-edges
  - `canonicaliseCycle()` — rotates a detected cycle to a canonical signature for deduplication

## Approach
These methods are static-analysis helpers — they never evaluate a Twig template, only parse its source. They are the implementation side of the existing "Circular Dependency Detection" requirement. The existing requirement covers the **outcome** (cycles are detected, evaluation refuses to enter an infinite loop); the new REQs document the **algorithm** that produces that outcome:

- Identifier extraction is **regex-based**, not AST-based, because a full Twig parser would require binding a sandbox environment just for static analysis.
- The dependency graph **only contains edges between computed properties**. Non-computed inputs are inert leaves — they cannot close a cycle, so they are filtered out before DFS.
- Cycle detection is **DFS-based** with an explicit traversal stack; a back-edge appears when the current node is already on the stack.
- Cycle deduplication uses a **canonical signature** (rotate to the lexicographically smallest node) so that DFS-entering the same cycle from two starting nodes yields one report, not two.

Source: rspec batch `/tmp/or-scan/rspec-cluster-computed-fields.json` (2026-05-24); triaged DROP from `object-lifecycle#REQ-004` cluster.
