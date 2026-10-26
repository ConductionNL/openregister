## ADDED Requirements

### Requirement: A fragment may put appointments on its objects

A fragment MAY declare `appointments`. Each row SHALL name `object`, `owner`, `summary` and `start`, and MAY give `calendar`, `end`, `location`, `description` and `attendees` (`{name?, email, status?}`). The owner SHALL be an account the set creates or adopts. On install the set SHALL write the event, with its invitees and their participation, to the owner's calendar, and link it to the object. A second install SHALL keep it, and remove SHALL delete the event and its link.

#### Scenario: an explanation at the desk
- **GIVEN** a row on case 710d44a4: "Toelichting stukken aan de verzoeker", 14 okt 10:00-10:30, Stadskantoor balie 3, Sanne de Vries invited
- **WHEN** the set is installed
- **THEN** the case's Afspraken card lists it, and Sanne de Vries is invited

#### Scenario: an appointment on a task
- **GIVEN** a row whose `object` is `{{task:hoorzitting-inplannen.uuid}}`, a task the fragment declares
- **WHEN** the set is installed
- **THEN** the event is linked to the task, with the register and schema of the record the task is on, and the task page's Afspraken card lists it

#### Scenario: a refresh on a later day
- **GIVEN** an appointment three days after the install day
- **WHEN** the set is refreshed five days later
- **THEN** the event is written again three days after the new day, and the old event and its link are gone
