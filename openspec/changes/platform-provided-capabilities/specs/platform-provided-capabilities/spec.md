# platform-provided-capabilities Specification (delta)

## Purpose

What OpenRegister takes from Nextcloud instead of building itself: sign-in, accounts, sessions, the administrator network check, file storage, installation, the interface language and the language of mail. Each requirement names the Nextcloud surface relied on and the behaviour that keeps it working. Covers rows acc-sso, acc-ldap, acc-sessions, acc-passkey, acc-ip-allowlist, file-external-storage, op-install, op-dutch-ui and op-mail-language.

## ADDED Requirements

### Requirement: Sign-in is the Nextcloud sign-in (REQ-PPC-001)

OpenRegister SHALL have no login page and no identity provider integration of its own. Every page and API route for signed-in users SHALL rely on the Nextcloud session or a Nextcloud app password, so that any sign-in method the instance offers (password, single sign-on through user_oidc or user_saml, LDAP) gives access to OpenRegister without further configuration.

#### Scenario: A user signed in through single sign-on opens OpenRegister
- **GIVEN** an instance where users sign in through the organisation's OpenID Connect provider
- **WHEN** a signed-in user opens `/apps/openregister/`
- **THEN** the dashboard loads without asking for credentials
- @e2e exclude {needs an identity provider in the stack; covered by the route posture test in task 2.1}

#### Scenario: No identity provider code ships in OpenRegister
- **GIVEN** the repository at any release tag
- **WHEN** `lib/` is searched for an OpenID Connect, SAML or LDAP client
- **THEN** none is found
- @e2e exclude {static property, asserted by tests/Unit/Platform/PlatformPropertiesTest.php in task 2.2}

### Requirement: Accounts and groups come from Nextcloud user backends (REQ-PPC-002)

OpenRegister SHALL read users through `OCP\IUserManager` and groups through `OCP\IGroupManager`, and SHALL NOT keep a user list or password store of its own. The `POST /api/user/login` route SHALL verify credentials through `IUserManager::checkPassword()`, so an account served by LDAP, Active Directory or another user backend signs in the same way as a local account.

#### Scenario: An LDAP account is used in an authorisation rule
- **GIVEN** a group `vergunningen` served by the LDAP backend and a schema with `read: ["vergunningen"]`
- **WHEN** a member of that LDAP group lists the schema's objects
- **THEN** the objects are returned
- @e2e exclude {needs an LDAP server in the stack; group resolution through IGroupManager is covered by the RBAC unit tests}

#### Scenario: The login route checks the password through Nextcloud
- **GIVEN** a user whose account lives in a user backend other than the database
- **WHEN** the user calls `POST /api/user/login` with valid credentials
- **THEN** the credentials are checked by `IUserManager::checkPassword()` and the call succeeds
- @e2e exclude {asserted by UserControllerTest with a mocked IUserManager in task 2.3}

### Requirement: Sessions are Nextcloud sessions (REQ-PPC-003)

OpenRegister SHALL keep no session, refresh token or device list of its own. A session or app password that a user or administrator revokes in Nextcloud's security settings SHALL stop giving access to OpenRegister from the next request.

#### Scenario: A device is signed out remotely
- **GIVEN** a user signed in to OpenRegister on a second device with a Nextcloud app password
- **WHEN** the user revokes that device in Nextcloud's personal security settings
- **THEN** the next API request from that device to OpenRegister answers 401
- @e2e exclude {needs two browser sessions and the Nextcloud security page; the absence of an own session store is asserted in task 2.2}

### Requirement: A second factor the instance enforces is never skipped (REQ-PPC-004)

OpenRegister SHALL accept whichever sign-in method Nextcloud completed, including a passkey, a security key or a second factor. No OpenRegister route SHALL create a signed-in session for a user who has a second factor enabled without that factor having been passed.

#### Scenario: A user with a security key opens OpenRegister
- **GIVEN** a user who signs in to Nextcloud with a WebAuthn security key
- **WHEN** the user opens OpenRegister after signing in
- **THEN** OpenRegister loads without a further prompt
- @e2e exclude {needs a virtual authenticator; covered by the route posture test in task 2.1}

#### Scenario: The login route meets a user with two-factor enabled
- **GIVEN** a user with a second factor enabled
- **WHEN** the user calls `POST /api/user/login` with only a valid password
- **THEN** no signed-in session is created and the response says a second factor is required
- @e2e exclude {asserted by UserControllerTest in task 2.3}

### Requirement: Administrator rights pass Nextcloud's admin network check (REQ-PPC-005)

OpenRegister SHALL decide whether a caller is an administrator through Nextcloud's own check (`IGroupManager::isAdmin()` or the admin-required middleware and `#[AuthorizedAdminSetting]`), so that Nextcloud's `allowed_admin_ranges` setting applies to every OpenRegister administrator action. OpenRegister SHALL NOT keep its own IP allowlist.

#### Scenario: An administrator outside the allowed range
- **GIVEN** `allowed_admin_ranges` lists only `10.0.0.0/8`
- **AND** a member of the `admin` group calling from `192.0.2.10`
- **WHEN** the caller requests an OpenRegister administrator route, such as saving the RBAC settings
- **THEN** the request is refused as for a non-administrator
- @e2e exclude {needs a client address outside the range; asserted by unit tests on the admin check in task 2.4}

### Requirement: Files are written through Nextcloud's storage layer (REQ-PPC-006)

OpenRegister SHALL read and write object files only through `OCP\Files\IRootFolder` and the node API, and SHALL NOT open a storage backend or bucket itself. Whatever primary or external storage the instance configures (local disk, S3, Swift, SMB) SHALL hold OpenRegister's files with no OpenRegister setting.

#### Scenario: An instance with S3 primary storage
- **GIVEN** an instance whose primary storage is an S3 bucket
- **WHEN** a user attaches a file to an object
- **THEN** the file is stored in the bucket through Nextcloud and can be opened from the object and from the Files app
- @e2e exclude {needs an object store in the stack; the absence of storage clients in lib/ is asserted in task 2.2}

### Requirement: The app installs from the app store with nothing else to run (REQ-PPC-007)

OpenRegister SHALL install and enable from the Nextcloud app store on a supported Nextcloud version and database with no service outside Nextcloud. `appinfo/info.xml` SHALL declare only PHP, database and Nextcloud dependencies. Optional services (Solr, Elasticsearch, an LLM provider, ExApps) SHALL be off by default, and their absence SHALL NOT stop the app from enabling or its pages from loading.

#### Scenario: A fresh install with no extra services
- **GIVEN** a Nextcloud instance with only its database
- **WHEN** an administrator installs and enables OpenRegister from the app store
- **THEN** the app enables and a register, a schema and an object can be created
- @e2e exclude {the CI stack installs the app the same way; the info.xml check is task 2.2}

### Requirement: Every interface string is translatable (REQ-PPC-008)

Every user-facing string in OpenRegister's pages SHALL pass through `t('openregister', ...)` or `n('openregister', ...)` in the frontend and through `IL10N` in PHP. The app SHALL ship a Dutch catalogue in `l10n/nl.json`, and the interface SHALL follow the language the user chose in Nextcloud.

#### Scenario: A Dutch user opens the dashboard
- **GIVEN** a user whose Nextcloud language is Nederlands
- **WHEN** the user opens the OpenRegister dashboard
- **THEN** the navigation and headings are shown in Dutch
- @e2e exclude {asserted by the translation lint in task 3.1}

#### Scenario: A hard-coded string is added
- **GIVEN** a component in `src/` with literal user-facing text outside `t()`
- **WHEN** the translation lint runs
- **THEN** it reports the string and its file
- @e2e exclude {lint behaviour, task 3.1}

### Requirement: Mail goes out in the recipient's language (REQ-PPC-009)

Mail that OpenRegister sends to a Nextcloud user SHALL be rendered in that user's Nextcloud language, read through `IFactory::getUserLanguage()`, with the instance default as the fallback. Account mail such as verification and password reset SHALL stay Nextcloud's own and SHALL NOT be duplicated by OpenRegister.

#### Scenario: A notification mail to a Dutch user
- **GIVEN** a schema notification rule with the `email` channel and a recipient whose Nextcloud language is Nederlands
- **WHEN** the rule fires
- **THEN** the mail subject and body are rendered with the Dutch catalogue
- @e2e exclude {asserted by unit tests on the email channel in task 2.5}
