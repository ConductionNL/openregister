## ADDED Requirements

### Requirement: An adopted account gains the profile fields it lacks

For an account the set adopted, the install SHALL write the cast person's function and role only where the account's headline or role is empty. It SHALL NOT overwrite a value the account holds, and SHALL NOT write absence or status.

#### Scenario: Pieter's function
- **GIVEN** the adopted account pieter, with role "Woo-coördinator" and no headline
- **WHEN** the set is installed with Pieter's function "Woo-coördinator"
- **THEN** his headline is "Woo-coördinator", and his role is unchanged
