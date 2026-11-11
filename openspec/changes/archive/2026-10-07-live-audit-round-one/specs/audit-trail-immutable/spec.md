## ADDED Requirements

### Requirement: Sortable headers on OpenRegister's own lists sort

A header the schemas list or the audit trail list marks sortable SHALL sort
the list when clicked, both ways, and SHALL show that state (`aria-sort`).
The schemas list SHALL keep newest first until a header is chosen. The audit
trail list SHALL sort on the server by the action, timestamp, object,
register, user and schema columns, and SHALL keep the chosen sort while
paging.

#### Scenario: Sort schemas by title

- GIVEN the schemas "Zaak", "adres" and "Besluit"
- WHEN the administrator clicks the Title header
- THEN the list reads "adres", "Besluit", "Zaak"
- AND a second click reverses it

#### Scenario: Sort the audit trail by user and page on

- GIVEN the audit trail sorted by User ascending
- WHEN the administrator opens page 2
- THEN the request asks for `sort=user_name&order=ASC`
