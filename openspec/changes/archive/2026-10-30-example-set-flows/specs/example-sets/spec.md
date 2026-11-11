## ADDED Requirements

### Requirement: A fragment may declare flows

An example set fragment MAY declare flows of the app that ships it. Each flow MUST be checked before anything is
written, stored switched off, published unless the fragment says not to, written once, proved on reinstall and removed
with the set.

#### Scenario: A case type flow in the demo set
- GIVEN a fragment of app dossiq with a flow "Omgevingsvergunning regulier" of seven steps with canvas positions
- WHEN the set is installed
- THEN the flow is stored for dossiq, switched off, published as version 1, with the steps where the fragment put them
- AND installing again keeps it

#### Scenario: A faulty flow
- GIVEN a flow with a line to a step it does not have
- THEN the check refuses it and nothing is written

#### Scenario: Removing the set
- WHEN the set is removed
- THEN the flow is deleted with its versions
