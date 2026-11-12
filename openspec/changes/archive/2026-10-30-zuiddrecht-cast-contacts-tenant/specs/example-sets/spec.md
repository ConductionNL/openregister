# example-sets Delta

## ADDED Requirements

### Requirement: The Zuiddrecht cast holds the contacts, companies and second tenant the dossiq boards name

The Zuiddrecht cast SHALL hold:
- the residents Pieter Bos and Henk Visser, Henk at Beukenlaan 3;
- the companies Visser Bouw B.V. and Aannemersbedrijf Van Wijk B.V.;
- the OpenRegister organisation Gemeente Waterland;
- the employee Marieke Visser.

Each resident SHALL have a BRP entry and each company a KvK and an establishment entry. BSNs SHALL pass the eleven test and KvK numbers SHALL be example numbers.

#### Scenario: A new case finds a possible duplicate
- **GIVEN** the set is installed
- **WHEN** a handler starts a case for "Visser" at Beukenlaan 3
- **THEN** the contacts SHALL offer Henk Visser and Visser Bouw B.V.
