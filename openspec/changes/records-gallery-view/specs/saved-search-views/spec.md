# saved-search-views

## ADDED Requirements

### Requirement: A saved view can present records as a gallery

A saved view SHALL accept `presentation.viewType` `gallery` with `coverField`,
`titleField`, `cardFields` and `cardSize`. The save MUST refuse a `coverField` that is
not a file or image property of the view's schema, and any field that is not a
property of that schema. `coverField` SHALL default to the schema's
`objectImageField`.

#### Scenario: A caseworker saves a gallery of buildings

- **GIVEN** the schema `panden` with an image property `foto` set as its `objectImageField`
- **WHEN** a caseworker saves a view with `viewType` `gallery`, `titleField` `adres` and card fields `bouwjaar` and `gebruiksdoel`
- **THEN** the view reads back with that presentation and `coverField` `foto`
- @e2e exclude {specified only; task 2.3 adds the editor path to tests/e2e/gallery-view.spec.ts}

#### Scenario: A text field cannot be the cover

- **GIVEN** the same schema
- **WHEN** a client saves a gallery view with `coverField` `adres`
- **THEN** the save is refused with a validation error naming `coverField`
- @e2e exclude {specified only; task 1.1 adds the validation test}

### Requirement: The search page renders a gallery view as cards

The search page SHALL render a gallery view as a paged grid of cards with the cover,
the title and up to four card fields, SHALL show the schema icon when a record has no
cover or the viewer may not read it, and SHALL open the record when a card is chosen.

#### Scenario: A caseworker browses buildings by photo

- **GIVEN** the gallery view of `panden` and 120 buildings, 100 with a photo
- **WHEN** a caseworker opens the view
- **THEN** the page shows cards with photos for the buildings that have one and the schema icon for the rest
- **AND** choosing a card opens that building's detail page
- @e2e exclude {specified only; task 2.1 adds tests/e2e/gallery-view.spec.ts}
