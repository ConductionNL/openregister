# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may let the set's own accounts follow its objects

A fragment MAY declare `watchers`, each with a `key` and the fields `user`, `objectUuid`, and optionally `register`, `schema` and `notify`, placeholders allowed. Install SHALL subscribe the user to the object once and record the pair. A later install SHALL leave a recorded follow as it is and SHALL report one that is gone as missing.

The check SHALL refuse:
- a `user` that is not an account the set creates or adopts;
- a row without `objectUuid`;
- a `notify` that is not true or false;
- any other field.

#### Scenario: Anouk follows the Woo case
- **GIVEN** a fragment declares a watcher with user `{{cast:anouk.uid}}` on its Woo case
- **WHEN** the set is installed
- **THEN** `anouk.bakker` SHALL follow the case, and the case page SHALL show her among its followers

### Requirement: Remove takes away the follows a set wrote

Remove SHALL unsubscribe each follow the record holds, and only those.

#### Scenario: Removing the set
- **GIVEN** the set was installed with one follow
- **WHEN** the set is removed
- **THEN** the follow SHALL be gone and the report SHALL count one removed watcher
