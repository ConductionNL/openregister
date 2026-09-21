# Tasks: contacts-leaf-cases-panel

## 1. Provider

- [x] 1.1 The reverse lookup, grouped by schema with title and status
      joined, in two classes rather than one method:
      `lib/Service/Integration/ContactCasesPanel.php` groups (pure, no
      address book and no database in sight) and
      `lib/Service/Integration/ContactCasesResolver.php` resolves each link
      into a row.
      **An object the reader may not see is COUNTED, never named, and never
      dropped.** Dropping it makes the panel say a contact is involved in two
      cases when they are involved in five, with nothing on screen to say so.
      The tally is deliberately flat rather than per schema: which register
      somebody appears in is most of what the reader was not allowed to know.
      **The reads are bounded**, because one read per link means an unbounded
      list is an unbounded number of reads to render a sidebar, and the cut is
      declared as `truncated` rather than left to look like the whole answer.
- [ ] 1.2 `GET /api/integrations/contacts/search?q=` over
      `IManager::search()`, limited to readable address books.

## 2. Surfaces

- [ ] 2.1 Detail surface: contact card plus the cases panel.
- [ ] 2.2 Index surface: name search with a result list that opens the
      detail surface.
- [ ] 2.3 Register both as leaf surfaces so a manifest can place them.

## 3. Tests

- [x] 3.1 Unit tests for the grouping and the bound:
      `ContactCasesPanelTest` (8) and `ContactCasesResolverTest` (7). The
      read bound is asserted by COUNTING the reads rather than by trusting
      the constant. The readable-address-book bound itself is `ContactService`'s
      existing IDOR guard (`currentUserAddressbookIds()`), which these classes
      narrow further and widen never; its own tests cover it.
- [ ] 3.2 `tests/e2e/ci/contacts-leaf-cases-panel.spec.ts`: link a contact
      to two objects, open the detail surface, see both under their schema.
