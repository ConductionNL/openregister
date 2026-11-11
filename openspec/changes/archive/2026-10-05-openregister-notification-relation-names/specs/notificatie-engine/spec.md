## ADDED Requirements

### Requirement: Relation placeholders resolve to display names

The notification `{{prop}}` interpolation SHALL resolve a UUID-shaped field value to the related object's display name (via OpenRegister `ObjectService`, RBAC-scoped) before substitution, and SHALL fall back to the raw value when the value is not a UUID, the object cannot be resolved, or the object has no name.

#### Scenario: Relation UUID renders as a name

- **WHEN** a notification subject/message contains `{{client}}` and the object's `client` field holds the UUID of a Client named "Acme Gemeente BV"
- **THEN** the rendered text reads "… Acme Gemeente BV …" rather than the UUID

#### Scenario: Non-relation placeholder is unchanged

- **WHEN** a placeholder resolves to a non-UUID scalar (e.g. `{{channel}}` = "telefoon")
- **THEN** the value is substituted verbatim (no resolution attempted)

#### Scenario: Unresolvable relation keeps the raw value

- **WHEN** a `{{prop}}` UUID cannot be resolved (no access, missing object, or nameless)
- **THEN** the raw UUID value is substituted and no error is raised
