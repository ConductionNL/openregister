# entity-management-modals

## ADDED Requirements

### Requirement: The modal host SHALL mount a modal only while it is open

The modal host MUST mount each modal only while the navigation key that opens
it (`navigationStore.modal` or `navigationStore.dialog`) holds that modal's
value, and MUST load the modal's code when it first opens, not with the page.
A modal that initialises its form or its checks when it opens MUST do so when
it mounts open.

#### Scenario: No modal is built on a page load

- **GIVEN** an OpenRegister page with no modal or dialog open
- **WHEN** the page loads
- **THEN** the modal host SHALL mount no modal
- **AND** no modal's code or styles SHALL be part of the chunks every page loads

#### Scenario: A modal mounts when opened and unmounts when closed

- **GIVEN** an OpenRegister page
- **WHEN** `navigationStore.modal` becomes `uploadFiles`
- **THEN** the upload modal SHALL be loaded and mounted
- **AND** when `navigationStore.modal` is cleared it SHALL be unmounted

#### Scenario: A delete dialog checks what still uses the item when it opens

- **GIVEN** the delete-schema dialog is closed
- **WHEN** `navigationStore.dialog` becomes `deleteSchema`
- **THEN** the dialog SHALL mount and load the objects that still use the schema
