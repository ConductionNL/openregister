## ADDED Requirements

### Requirement: A linked event lists the people invited

Each linked event SHALL list its invitees, read from the event: `name`, `email` and `partstat`. An event that cannot be read SHALL list none and still show.

#### Scenario: an explanation at the desk
- **THEN** the appointment lists Sanne de Vries with partstat `needs-action`
