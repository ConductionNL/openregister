# example-sets Delta: example-set-group-subadmins

## ADDED Requirements

### Requirement: A group may name its sub-admins from the cast

A cast group or a fragment group MAY declare `subAdmins`, a list of cast keys of people with an account. Install SHALL make each named account a Nextcloud sub-admin of that group when it is not one yet, SHALL NOT make it a member or an administrator, and SHALL record what it made. The proof SHALL name a recorded sub-admin right that is gone. Remove SHALL take away only the sub-admin rights the record holds, before it removes memberships and accounts. A key that is no cast person with an account SHALL refuse the set before anything is written.

#### Scenario: A sub-admin is made, kept and taken away
- **GIVEN** a fragment group with member Esra and sub-admin Sanne
- **WHEN** the set is installed twice and then removed
- **THEN** Sanne SHALL be sub-admin after the first install (reported created), still after the second (reported kept), and not after remove; Sanne SHALL never be a member

#### Scenario: An unknown sub-admin refuses the set
- **GIVEN** a group naming a sub-admin key that is not a cast person with an account
- **WHEN** the set is checked
- **THEN** the set SHALL be refused with that key named, and nothing SHALL be written
