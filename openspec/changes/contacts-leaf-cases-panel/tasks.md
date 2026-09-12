# Tasks: contacts-leaf-cases-panel

## 1. Provider

- [ ] 1.1 `ContactsProvider::objectsForContact(uri)` groups the reverse
      lookup by schema and joins title and status.
- [ ] 1.2 `GET /api/integrations/contacts/search?q=` over
      `IManager::search()`, limited to readable address books.

## 2. Surfaces

- [ ] 2.1 Detail surface: contact card plus the cases panel.
- [ ] 2.2 Index surface: name search with a result list that opens the
      detail surface.
- [ ] 2.3 Register both as leaf surfaces so a manifest can place them.

## 3. Tests

- [ ] 3.1 Unit tests for grouping and for the readable-address-book bound.
- [ ] 3.2 `tests/e2e/ci/contacts-leaf-cases-panel.spec.ts`: link a contact
      to two objects, open the detail surface, see both under their schema.
