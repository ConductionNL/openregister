# Tasks: rule-inventory-scope

## 1. Scope on the descriptor
- [x] 1.1 `RuleDescriptor` carries and returns `scope`.
- [x] 1.2 `RuleInventoryService` reads `scope` from calculations, state field blocks, transitions and a flow's object
      trigger node in its published graph.
- [x] 1.3 `TriggerObjectNode` accepts and checks `scope`, so the preflight does not refuse it.

## 2. The filter
- [x] 2.1 `RuleInventoryService::inventory()` takes a scope filter: rules with that scope plus rules without one.
- [x] 2.2 `RulesController::index()` passes `scope[<property>]=<value>`.
- [x] 2.3 Unit tests: the filter, the unfiltered list, a flow scoped through its trigger node.
