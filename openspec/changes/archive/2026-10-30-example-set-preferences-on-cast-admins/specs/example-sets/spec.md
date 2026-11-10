# example-sets Delta: example-set-preferences-on-cast-admins

## MODIFIED Requirements

### Requirement: A fragment may set its own app's preferences for the set's own accounts (REQ-EXS-024)

A fragment MAY declare `userPreferences`, each entry a `user` (`{{cast:<key>.uid}}` or `{{account:<key>.uid}}`), a `key` and a scalar `value` (placeholders allowed), written with `IConfig::setUserValue`. An entry SHALL only set preferences of the app that ships the fragment; naming another app SHALL refuse the set. The account SHALL be one the set's cast declares, created or adopted; such an account MAY be in Nextcloud's administrators group. An account outside the cast SHALL be refused, with its own reason when it is an administrator, and a key whose name holds a secret (password, secret, token, credential, private, api key, session) or that Nextcloud keeps as sensitive SHALL be refused; each refusal is made by the check, before anything is written. Install SHALL write only a value that differs and SHALL record the old value. Remove SHALL put the old value back, or delete the key when there was none, but SHALL leave a value that changed after the install, naming it, and SHALL do so before it deletes the accounts it created.

#### Scenario: The demo staff skip the tour
- **GIVEN** dossiq's fragment gives `{{cast:esra.uid}}` the preference `pref_walkthrough_completed_version` = `1.2.0`
- **WHEN** the set is installed twice and then removed
- **THEN** Esra SHALL hold `1.2.0` after the first install, the second install SHALL write nothing, and after remove the key SHALL be gone

#### Scenario: An adopted account gets its own value back
- **GIVEN** an adopted account that held `0.9.0`
- **WHEN** the set writes `1.2.0` and is then removed
- **THEN** the account SHALL hold `0.9.0` again, unless somebody changed the value after the install, which SHALL stay and be named

#### Scenario: An administrator the cast declares gets the preference
- **GIVEN** a preference for a cast account that was adopted and is one of Nextcloud's administrators
- **WHEN** the set is installed
- **THEN** the preference SHALL be written, and the administrators group SHALL be unchanged

#### Scenario: An administrator, a foreign account and a secret are refused
- **GIVEN** a preference for an account the set does not declare, one for an administrator outside the cast, and one for a key named `api_token`
- **WHEN** the set is checked
- **THEN** each SHALL be refused with its reason, and nothing SHALL be written
