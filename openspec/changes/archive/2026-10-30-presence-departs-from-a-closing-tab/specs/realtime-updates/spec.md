## ADDED Requirements

### Requirement: A closing tab can say it left

A client whose tab is closing can only send a beacon, which is always a POST.
OpenRegister SHALL accept `POST /api/objects/{register}/{schema}/{id}/presence`
carrying `_method=DELETE` as a departure, with the same effect and answer as
`DELETE` on that url. A POST without that marker SHALL be refused with 400 and
SHALL depart nobody. The route SHALL keep Nextcloud's CSRF check.

#### Scenario: a beacon departs

- GIVEN alice has the object open
- WHEN her closing tab sends `POST .../presence?_method=DELETE` with her request token
- THEN she is departed and the answer lists who is left

#### Scenario: a stray POST changes nothing

- GIVEN alice has the object open
- WHEN a POST reaches `.../presence` without `_method=DELETE`
- THEN the answer is 400 and alice is still present
