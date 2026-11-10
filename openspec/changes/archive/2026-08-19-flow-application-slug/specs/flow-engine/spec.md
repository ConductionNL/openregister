## ADDED Requirements

### Requirement: A flow may declare the virtual-app it belongs to, and be listed by it

A flow MAY carry an `applicationSlug`, identifying the OpenBuild virtual-app
it belongs to. It is optional and defaults to absent: a flow with no
`applicationSlug` MUST remain a fully valid, ordinary flow, and existing
flows MUST NOT be backfilled with one as part of adding the field.

`applicationSlug` is independent of `app`. `app` is the owning Nextcloud app
(e.g. `hermiq`); `applicationSlug` is narrower — one Nextcloud app may host
several virtual apps, each with its own flows, and `app=hermiq` alone
cannot distinguish between them.

`GET /apps/openregister/api/flows` MUST accept an optional
`applicationSlug` query parameter. When supplied, the result MUST be
restricted to flows whose stored `applicationSlug` equals it exactly; when
omitted or empty, the endpoint MUST behave exactly as it does today —
every flow visible to the caller, regardless of `applicationSlug`. The two
filters compose: passing both `app` and `applicationSlug` MUST narrow by
both.

`applicationSlug` MUST be a client-editable field on create and update,
alongside the other descriptive string fields (e.g. `name`, `description`).
It MUST NOT be treated as a stamped/server-owned field the way `owner` and
`organisation` are: any caller permitted to create or update a flow may set
or clear it.

#### Scenario: A flow with no applicationSlug is listed and served unchanged

- **GIVEN** a flow that has never had `applicationSlug` set
- **WHEN** it is read via `GET /apps/openregister/api/flows/{id}` or listed
  via `GET /apps/openregister/api/flows` with no `applicationSlug` filter
- **THEN** it is returned exactly as before, with `applicationSlug: null`

#### Scenario: Filtering narrows to exactly the matching virtual-app's flows

- **GIVEN** flows with `applicationSlug` values `"hydra"`, `"other-app"`,
  and one flow with no `applicationSlug` at all
- **WHEN** `GET /apps/openregister/api/flows?applicationSlug=hydra` is called
- **THEN** only the flow(s) with `applicationSlug: "hydra"` are returned

#### Scenario: An absent or empty filter returns every flow, matching current behaviour

- **GIVEN** a mix of flows, some with `applicationSlug` set and some without
- **WHEN** `GET /apps/openregister/api/flows` is called with no
  `applicationSlug` parameter
- **THEN** every flow visible to the caller is returned, unaffected by
  whether it carries an `applicationSlug`

#### Scenario: The app and applicationSlug filters compose

- **GIVEN** flows across several Nextcloud apps, some sharing the same
  `applicationSlug`
- **WHEN** `GET /apps/openregister/api/flows?app=hermiq&applicationSlug=hydra`
  is called
- **THEN** only flows with `app: "hermiq"` AND `applicationSlug: "hydra"`
  are returned

#### Scenario: A partial update that omits applicationSlug leaves it unchanged

- **GIVEN** a stored flow with `applicationSlug: "hydra"`
- **WHEN** it is updated with a payload that omits the `applicationSlug` key
- **THEN** the stored `applicationSlug` remains `"hydra"`

#### Scenario: An explicit null clears applicationSlug

- **GIVEN** a stored flow with `applicationSlug: "hydra"`
- **WHEN** it is updated with `applicationSlug: null`
- **THEN** the stored `applicationSlug` becomes null
