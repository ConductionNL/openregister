# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may declare saved views for the set's own accounts

A fragment MAY declare `views`, each entry a `key` unique in the fragment and `data` with `name`, `description`,
`owner`, `isPublic`, `query`, `presentation` and `alert`, placeholders allowed. The check SHALL refuse, before anything
is written, a field outside that list, a view without a name, an owner that is not an account the set creates or adopts,
and a `query`, `presentation` or `alert` that is not an object. Install SHALL write each view once through the view
service, which validates its presentation and alert, SHALL record its uuid, and SHALL report a recorded view that is no
longer there as missing. An OpenRegister that cannot write views SHALL refuse a fragment that declares them.

#### Scenario: A saved search with a count alert
- **GIVEN** dossiq's fragment declares a public view owned by `{{cast:thomas.uid}}` with query `search: "Lindelaan"` and
  `alert: {operator: "gte", threshold: 12}`
- **WHEN** the set is installed twice
- **THEN** the view SHALL exist once with that owner, query and alert, and the second install SHALL write nothing

### Requirement: Remove deletes the views a set wrote

Remove SHALL delete the views the record holds, with the tasks and before the objects, newest first, and SHALL count
them. A view already gone SHALL count as removed.

#### Scenario: Remove leaves no view behind
- **GIVEN** an installed set with a saved view
- **WHEN** the set is removed
- **THEN** the view SHALL be gone and the record SHALL hold none

### Requirement: The cast carries the jeugdteam regisseur

The Zuiddrecht cast SHALL carry Fatma Kaya (`fatma.kaya`, regisseur jeugdteam, groups medewerkers and Sociaal domein),
the handler of dossiq's jeugdwet example case.

#### Scenario: the shipped set
- **GIVEN** the shipped Zuiddrecht set
- **THEN** its cast holds Fatma Kaya in Sociaal domein

### Requirement: The cast carries the handler the dossiq boards draw

The Zuiddrecht cast SHALL carry Pieter Jansen (`pieter`, behandelaar, groups medewerkers and Vergunningen), the handler
dossiq's boards draw as the reader, so a fragment can give him a saved view of his own.

#### Scenario: the shipped set
- **GIVEN** the shipped Zuiddrecht set
- **THEN** its cast holds 18 people, Pieter Jansen among them in Vergunningen with the account id `pieter`
