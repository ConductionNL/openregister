# Tasks: or-flow-expressions

- [x] Move `jwadhams/json-logic-php` into OpenRegister's own dependencies.
- [x] `FlowExpression` — data document, evaluate, isTrue, isValid.
- [x] Custom operators: strings, dates, arrays, structure.
- [x] `FilterNode` with provenance-preserving survivors.
- [x] `WaitNode` with suspend-then-pass-through.
- [x] Register all built-ins through the contribution event.
- [x] Tests: 21 covering scope, falsiness, validity, every operator, filter
      provenance, empty results, wait suspension/resume/bare-seconds/absolute.
- Not in this change (the flow editor is the shared nextcloud-vue canvas): Expression editor with the data in scope (UI change).
- [x] If/Switch multi-branch routing — needs labelled edge outputs (#2067). (built: lib/Service/Flow/Nodes/SwitchNode.php and RouterNode.php (or-flow-per-item-routing))
