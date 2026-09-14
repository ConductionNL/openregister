# Tasks: view-group-share

## 1. Data and query

- [ ] 1.1 `sharedWith` on `View` with a migration; group existence validated on write.
- [ ] 1.2 `ViewMapper::findAllFor(user)` unioning owner, public and group membership; `@self.access` on each row.

## 2. Guards

- [ ] 2.1 Owner-or-admin on `sharedWith`, `owner` and delete; `write` members limited to `query`, `presentation`, `alert`.

## 3. Tests

- [ ] 3.1 Unit tests for the list union and the guards; Newman for list, share and the 403.
