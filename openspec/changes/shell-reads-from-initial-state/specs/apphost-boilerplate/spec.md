## ADDED Requirements

### Requirement: The app shell's boot reads ride along with the page

An OpenRegister SPA page SHALL carry, as initial state of the app
`openregister`, the walkthrough preferences of the signed-in user under
`walkthrough_preferences` as `{seen, progress}`. Each value SHALL be the one
`GET /api/preferences/{key}` answers for `walkthrough_seen_version` and
`walkthrough_seen_version-progress` (a string, or null when unset). For an
administrator, the page SHALL also carry the setup status document under
`setup_status`. For any other user it SHALL leave that key out, so the shell
asks the admin-only endpoint itself and gets that endpoint's own answer. A page
rendered for a guest SHALL carry neither.

#### Scenario: An administrator opens Registers

- **GIVEN** an administrator who has seen walkthrough version 2.1.0
- **WHEN** they open `/apps/openregister/registers`
- **THEN** the page carries `walkthrough_preferences` = `{seen: "2.1.0", progress: null}` and `setup_status`
- **AND** the page makes no request to `/api/setup/status` or `/api/preferences/walkthrough_seen_version*`

#### Scenario: A user who is not an administrator opens Registers

- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they open `/apps/openregister/registers`
- **THEN** the page carries `walkthrough_preferences` and no `setup_status`
- **AND** the shell asks `/api/setup/status` as before
