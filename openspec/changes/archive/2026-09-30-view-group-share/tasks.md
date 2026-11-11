# Tasks: view-group-share

## 1. Data and query

- [x] 1.1 `sharedWith` on `View` with a migration; group existence validated on write.
- [x] 1.2 `ViewMapper::findAllFor(user)` unioning owner, public and group membership; `@self.access` on each row.

## 2. Guards

- [x] 2.1 Owner-or-admin on `sharedWith`, `owner` and delete; `write` members limited to `query`, `presentation`, `alert`.

## 3. Tests

- [x] 3.1 Unit tests for the list union and the guards (13 cases on
      `ViewShareResolver`, plus the controller suite rewired to the new list
      method). **Newman is NOT run here**: this phase's clone has no instance
      to point a collection at, and a collection written and never executed is
      a file that claims coverage. It belongs with the live-instance sweep.
