---
retrofit: true
---

# Computed Fields — Spec Delta

## ADDED Requirements

### Requirement: Static Identifier Extraction From Twig Expression Source
The system MUST extract candidate variable identifiers from a Twig expression by regex-scanning the source text of every `{{ ... }}` and `{% ... %}` block, without parsing the expression through a Twig AST or sandbox environment. The extractor MUST collect only the top-level word of dotted or array references (`foo`, `foo.bar`, `foo[0]` all yield `foo`). The extractor MUST skip a curated reserved-word set covering Twig keywords (`and`, `or`, `not`, `in`, `is`, `as`, `with`, `if`, `else`, `elseif`, `endif`, `for`, `endfor`, `set`, `do`, `block`, `endblock`), literals (`true`, `false`, `null`), and built-in helpers used inside expressions (`date`, `now`, `min`, `max`, `range`, `random`, `length`, `count`). The extractor MUST strip the cross-object reference prefix `_ref` from the result because `_ref.*` references are foreign-object lookups, not in-schema dependencies.

#### Scenario: Identifier extracted from a simple expression
- **GIVEN** the expression `{{ voornaam }} {{ achternaam }}`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST contain `voornaam` and `achternaam`
- **AND** the result MUST NOT contain any Twig keyword

#### Scenario: Top-level identifier of a dotted reference
- **GIVEN** the expression `{{ klant.naam }}`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST contain `klant`
- **AND** the result MUST NOT contain `naam` (it is a sub-property, not a top-level identifier)

#### Scenario: Cross-object reference prefix is stripped
- **GIVEN** the expression `{{ _ref.klant.naam }}`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST NOT contain `_ref`
- **AND** the identifier `_ref` MUST be filtered out because cross-object references are not in-schema dependencies

#### Scenario: Plain-text content outside Twig blocks is ignored
- **GIVEN** the expression `Hello {{ name }} world`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST contain `name`
- **AND** the result MUST NOT contain `Hello` or `world` (plain text outside `{{ }}` / `{% %}` is not scanned)

#### Scenario: Empty expression yields empty result
- **GIVEN** an expression with no `{{ }}` or `{% %}` blocks
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST be an empty array

### Requirement: Computed-Only Dependency Graph Construction
The system MUST build the cycle-detection dependency graph using only edges between computed properties. Before extraction, the system MUST collect the set of property names whose definition contains a `computed` array attribute. For each computed property, after extracting candidate identifiers from its expression, the system MUST filter the identifiers down to those also in the computed-property set; references to non-computed properties MUST be discarded because non-computed inputs are inert leaves that cannot close a cycle. The resulting graph MUST be an adjacency list keyed by computed property name with deduplicated edge targets.

#### Scenario: Non-computed property reference is filtered from graph
- **GIVEN** computed field `totaal` with expression `{{ aantal * prijs - korting }}`
- **AND** `aantal`, `prijs`, `korting` are non-computed (plain) properties
- **WHEN** the dependency graph is built
- **THEN** the graph entry for `totaal` MUST be an empty edge list
- **AND** no graph entry MUST be created for `aantal`, `prijs`, or `korting` (they are not computed)

#### Scenario: Computed-to-computed reference becomes a graph edge
- **GIVEN** computed field `subtotaal` (expression `{{ aantal * prijs }}`)
- **AND** computed field `totaal` (expression `{{ subtotaal - korting }}`)
- **WHEN** the dependency graph is built
- **THEN** the graph MUST contain an edge `totaal -> subtotaal`

#### Scenario: Schema with no computed properties yields an empty graph
- **GIVEN** a schema whose `properties` array contains no entry with a `computed` attribute
- **WHEN** `detectCircularDependencies` runs
- **THEN** the function MUST return an empty cycle list immediately without building the graph

#### Scenario: Computed property with empty expression has empty edge list
- **GIVEN** a computed property whose `computed.expression` is missing or the empty string
- **WHEN** the dependency graph is built
- **THEN** the graph entry for that property MUST be an empty edge list (the property is in the graph as a node but has no outgoing edges)

### Requirement: DFS-Based Back-Edge Cycle Detection
The system MUST detect cycles in the computed-field dependency graph by depth-first traversal. The traversal MUST maintain an explicit stack of nodes on the current path. When the traversal encounters a node that is already on the stack, that node is a back-edge target and the path from that node back to itself (the slice of the stack from the matched index plus the duplicated closing node) MUST be recorded as a cycle. The traversal MUST also maintain a globally-visited set so that subtrees already explored from another starting node are not re-walked. The traversal MUST be invoked once per node in the graph so cycles reachable only from non-root entry points are still detected.

#### Scenario: Self-loop is detected as a length-1 cycle
- **GIVEN** computed field `a` with expression `{{ a + 1 }}` (so `a -> a` is an edge)
- **WHEN** `dfsForCycles` is called for node `a`
- **THEN** the cycle `[a, a]` (start `a`, back-edge to `a`) MUST be recorded

#### Scenario: Two-node cycle is detected from either starting node
- **GIVEN** edges `a -> b` and `b -> a`
- **WHEN** DFS runs from node `a`
- **THEN** the cycle `[a, b, a]` MUST be recorded

#### Scenario: Shared subtree not re-walked
- **GIVEN** a graph with nodes `a`, `b`, `c` where both `a -> c` and `b -> c` exist and `c` is acyclic
- **WHEN** DFS runs from `a` and then from `b`
- **THEN** the subtree rooted at `c` MUST NOT be re-traversed during the second invocation (visited-set short-circuits the second walk)

#### Scenario: Acyclic graph produces no cycles
- **GIVEN** a graph `subtotaal -> []`, `totaal -> [subtotaal]` (no back-edges)
- **WHEN** DFS runs from every node
- **THEN** the returned cycle list MUST be empty

### Requirement: Canonical Cycle Signature Deduplication
The system MUST deduplicate detected cycles by canonical signature so that entering the same cycle from two different DFS starting nodes produces only one entry in the reported cycle list. The signature MUST be computed by dropping the duplicated closing node from the cycle path and then rotating the remaining sequence so the lexicographically smallest node leads. The rotated sequence MUST be joined by `->` to form the signature. The first time a signature is produced the cycle MUST be appended to the output and the signature recorded; subsequent re-discoveries with the same signature MUST be suppressed.

#### Scenario: Same cycle entered from two starts yields one report
- **GIVEN** the cycle `a -> b -> a` is reachable from both starting nodes `a` and `b`
- **WHEN** DFS visits both starts in turn
- **THEN** the cycle MUST appear exactly once in the returned cycle list
- **AND** the canonical signature MUST be `a->b` (rotation starting at the lexicographically smallest node)

#### Scenario: Cycle rotated to lexicographically smallest start
- **GIVEN** the cycle path `[c, a, b, c]` (closing node duplicated)
- **WHEN** `canonicaliseCycle` runs
- **THEN** the duplicated closing `c` MUST be dropped
- **AND** the body `[c, a, b]` MUST be rotated so `a` (smallest) leads
- **AND** the returned signature MUST be `a->b->c`

#### Scenario: Single-node self-loop produces a single-name signature
- **GIVEN** the cycle path `[a, a]`
- **WHEN** `canonicaliseCycle` runs
- **THEN** the duplicated closing `a` MUST be dropped
- **AND** the body `[a]` is already canonical
- **AND** the returned signature MUST be `a`

#### Scenario: Empty cycle returns empty signature
- **GIVEN** an empty cycle path
- **WHEN** `canonicaliseCycle` runs
- **THEN** the returned signature MUST be the empty string
