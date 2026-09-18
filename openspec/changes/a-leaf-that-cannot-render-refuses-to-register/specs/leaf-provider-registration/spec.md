# leaf-provider-registration

## ADDED Requirements

### Requirement: A leaf that cannot render refuses to register (REQ-LPR-020)

A contributed leaf declaring the render-surface kind SHALL be refused when the
app that provides it ships no leaf bundle, and the refusal SHALL name the leaf,
the providing app and the file the app must build. A leaf that declares no render
surface, a leaf provided by OpenRegister itself, and a leaf whose providing app is
disabled or unresolvable SHALL NOT be refused for this reason.

#### Scenario: a render surface with no bundle is refused

- **GIVEN** an enabled app that ships no `js/<app>-leaves.js`
- **WHEN** it contributes a render-surface leaf
- **THEN** the leaf is not registered
- **AND** the refusal names the file the app must build

#### Scenario: a data provider needs no bundle

- **GIVEN** the same app
- **WHEN** it contributes a data-provider leaf
- **THEN** the leaf registers

#### Scenario: a built-in leaf rides the platform's own bundle

- **GIVEN** a leaf whose providing app is not named
- **WHEN** it is contributed
- **THEN** it registers

#### Scenario: a disabled app is reported, not refused

- **GIVEN** a render-surface leaf whose providing app is disabled
- **WHEN** it is contributed
- **THEN** it registers and is reported unusable
- @e2e exclude {registration behaviour, covered by unit tests}
