# Tasks: or-flow-per-item-routing (design)

- [x] Record the gap, the Petri-net constraint, and the options.
- [x] Recommend option 2 (per-output routing in advanceItems, opt-in via an
      item output tag; untagged items unchanged).
- [ ] DECISION: agree option 2 before writing code.
- [ ] Implement: item output tag + advanceItems distribution; Switch/Filter opt-in.
- [ ] Test: split across branches; untagged multi-output copies to all; resume
      mid-split.
