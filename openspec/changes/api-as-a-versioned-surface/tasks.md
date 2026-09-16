# Tasks: api-as-a-versioned-surface

## 1. Links out of a record

- [ ] 1.1 A declared external link on a schema: title, URL template, condition (D-1).
- [ ] 1.2 Placeholders resolved from the object's own values, validated at schema save.
- [ ] 1.3 An unresolvable placeholder hides the link; the link set is returned with the object (D-2).

## 2. The version lifecycle

- [x] 2.1 A version status of supported, deprecated with an end date, or withdrawn (D-3).
- [x] 2.2 A deprecated version answers and carries its end date in the response.
- [x] 2.3 A withdrawn version answers 410 naming its successor.
- [x] 2.4 One generated OpenAPI document per served version.

## 3. Capabilities and limits

- [x] 3.1 A capabilities answer carrying versions, upload limit, page size, rate limits and enabled features (D-5).
- [x] 3.2 The unauthenticated part names no register, schema or operational flag.

## 4. The caller record and its bounds

- [ ] 4.1 Principal, route, version, count and last seen, with no payload (D-4).
- [ ] 4.2 An administrator reads the record for a period.
- [ ] 4.3 A rate limit per token or consumer, refused naming the limit and the reset time.
- [ ] 4.4 A source-address binding on a token, refusing another address; specified beside `scoped-api-tokens`.

## 5. Discovery and egress

- [ ] 5.1 The well-known paths with administered content, `security.txt` first.
- [ ] 5.2 One proxy setting read in one place and used by every outbound client (D-6).

## 6. Tests

- [~] 6.1 `tests/e2e/ci/api-surface.spec.ts`: a filled link, a hidden link, a deprecated call with its end date, a 410 on a withdrawn version. (The file exists and covers the published contract, the version stamp and the refusals a default instance can produce. The deprecated and withdrawn cases need an administered declaration and land with task 2's administration surface; they are proven by unit tests meanwhile.)
- [~] 6.2 Unit tests: placeholder resolution, the capabilities split, the caller record without payload, the rate limit over a clock fixture, the address binding, the proxy. (The capabilities split, the version lifecycle and the negotiation are done: 80 tests. The rest follow their own tasks.)
- [x] 6.3 `openspec validate api-as-a-versioned-surface --strict`.

## 7. Hand over

- [ ] 7.1 Hand the declared links to the dossiq lane for the per case type external links, with the fourteen candidate ids.
- [x] 7.2 Hand the capabilities answer to the integriq lane, which reads it instead of probing. (Contract published in the PR body: `GET /api/capabilities`, `GET /api/versions`, `GET /api/versions/{v}/oas`, the `API-Version` request header and the `API-Version`/`Deprecation`/`Sunset`/`Link` response headers.)
