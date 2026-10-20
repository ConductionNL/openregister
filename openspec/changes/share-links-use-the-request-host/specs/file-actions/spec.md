# file-actions

## ADDED Requirements

### Requirement: A file link points at the host the visitor used

Every absolute file address Open Register hands out (the public share link,
its `/download` form, and the authenticated preview and download addresses)
SHALL be built for the host of the current request, through Nextcloud's URL
generator, so `overwritehost`, `overwriteprotocol` and trusted proxies apply.
When there is no request (cron, occ) the address SHALL be built from
`overwrite.cli.url`, and only when that is empty from the URL generator's own
base. The address SHALL NOT take its host from an entry of `trusted_domains`,
and the order of that list SHALL NOT change any address.

#### Scenario: a visitor on localhost gets a localhost link

- **GIVEN** an instance whose `trusted_domains` are `localhost` and `nextcloud`
- **WHEN** a visitor on `http://localhost:8080` asks for the share link of token `abc123`
- **THEN** the link is `http://localhost:8080/index.php/s/abc123`
- @e2e exclude {host resolution is unit-tested in tests/Unit/Service/File/ShareUrlBuilderTest.php; e2e runs on one host}

#### Scenario: a visitor on the public host gets that host

- **GIVEN** an instance that answers on `zuiddrecht.nl` and `cloud.zuiddrecht.nl`, with `overwrite.cli.url` set to `https://cloud.zuiddrecht.nl`
- **WHEN** a visitor on `https://zuiddrecht.nl` asks for the share link of token `abc123`
- **THEN** the link is `https://zuiddrecht.nl/index.php/s/abc123`
- @e2e exclude {host resolution is unit-tested in tests/Unit/Service/File/ShareUrlBuilderTest.php; e2e runs on one host}

#### Scenario: without a request the link uses the CLI URL

- **GIVEN** `overwrite.cli.url` is `https://cloud.zuiddrecht.nl/`
- **WHEN** a cron job or occ command builds the share link, preview address or download address of a file
- **THEN** each address starts with `https://cloud.zuiddrecht.nl/`
- @e2e exclude {no browser takes part in a cron run; unit-tested in tests/Unit/Service/File/ShareUrlBuilderTest.php}

#### Scenario: the order of trusted domains is irrelevant

- **GIVEN** any order of `trusted_domains`, including an empty list or a single entry
- **WHEN** a visitor on `http://localhost:8080` asks for the share link of token `abc123`
- **THEN** the link is `http://localhost:8080/index.php/s/abc123` every time
- @e2e exclude {configuration permutations are unit-tested in tests/Unit/Service/File/ShareUrlBuilderTest.php}
