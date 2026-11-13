# Request token on write routes

Every POST, PUT, PATCH and DELETE route in OpenRegister runs Nextcloud's request-token check. This follows ADR-054 rule 2. We build on the Nextcloud check and add no mechanism of our own.

## How each caller passes the check

| Caller | What it sends |
|---|---|
| The OpenRegister UI and other app frontends | The `requesttoken` header. `@nextcloud/axios` and the `@conduction/nextcloud-vue` stores add it for you. A raw `fetch()` in the OpenRegister UI wraps its options in `withRequestToken()` from `src/services/requestToken.js`. |
| Scripts, Postman, Newman, n8n | Basic auth or an app password, plus `OCS-APIRequest: true`. |
| Anonymous calls to public routes (public object create and update) | `OCS-APIRequest: true`. |

This covers the whole data API: objects, files, registers, schemas, sources, bulk, configurations, organisations, chat, webhooks, agents, applications, mappings, endpoints and consumers.

A basic-auth request without `OCS-APIRequest: true` gets `412 Precondition Failed`. Add the header and send the request again.

```bash
curl -u admin:admin -H 'OCS-APIRequest: true' -X POST \
  -H 'Content-Type: application/json' \
  https://nextcloud.example/index.php/apps/openregister/api/views \
  -d '{"name": "Open cases"}'
```

## Routes that still skip the check

Some write routes keep `NoCSRFRequired`. Each one is listed with its reason in
`tests/Unit/Architecture/Fixtures/request-token-exempt-write-routes.php`.

| Reason | Meaning |
|---|---|
| `public-token` | A public page reached with a share token, or an anonymous form. These routes are rate limited. |
| `cors-api` | A `#[CORS]` API. Nextcloud refuses a cookie session that sends no token, and asks for basic auth. |
| `header-credential` | The caller signs the request with its own header, not the session cookie. |

`WriteRoutesRequireRequestTokenTest` fails when a write route skips the check without being listed. It also fails when a listed route no longer skips it.

## Adding a write route

1. Leave `NoCSRFRequired` off the controller method.
2. Call the route from the frontend through `@nextcloud/axios`, a library store, or `fetch()` with `withRequestToken()`.
3. Send `OCS-APIRequest: true` from every Postman request and e2e API call that hits it.
