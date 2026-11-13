# Client libraries

Open Register is called from outside Nextcloud by portals, integrations and scripts. This page covers how such a caller identifies itself, how it stays on one contract version, and how to generate a client for a language without an official library.

## Official libraries

Two official libraries are planned: `@conduction/openregister-client` for TypeScript on npm and `openregister-client` for Python on PyPI. They are not published yet. This page gains their install, authentication and typed-model sections when the first release exists.

Until then, the version rule and the identification below are already served by Open Register, and any HTTP client can follow them.

## Identify your client

Open Register keeps a caller record: per caller, route, method, contract version and client library, how often and when it was called. An administrator reads it at `GET /api/callers`.

The client field is filled only for a `User-Agent` that starts with `openregister-client-ts/<x.y.z>` or `openregister-client-python/<x.y.z>`. Every other `User-Agent`, a browser's included, is recorded as an empty client. The raw header is never stored.

```http
GET /index.php/apps/openregister/api/objects/zaken/zaak?_limit=10
API-Version: 1
User-Agent: openregister-client-ts/1.4.0
```

## Stay on one contract version

Send `API-Version: <major>` on every request, for example `API-Version: 1`. Never leave it out: an instance that moves its default would then move your integration.

1. On first use, read `GET /api/versions` once. When your major is listed as neither `supported` nor `deprecated`, stop and report the versions the instance serves.
2. When a response carries a `Deprecation` header, warn once per process. Name the date in `Sunset` and the successor version in `Link`.
3. A `410 Gone` means the contract was withdrawn. The body names `successorVersion`.

## Generate a client for another language

Java, C# and PHP have no official library. Generate one with [openapi-generator](https://openapi-generator.tech) from the OpenAPI document of one contract version, narrowed to the register you call:

```sh
curl -u "$USER:$APP_PASSWORD" \
  "https://cloud.example.org/index.php/apps/openregister/api/versions/1/oas?register=zaken" \
  -o openregister-zaken.json

openapi-generator-cli generate -i openregister-zaken.json -g java -o ./openregister-client-java
```

Use `-g csharp` or `-g php` for the other two languages. Keep sending `API-Version` and follow the version rule above in the code that calls the generated client.

## Credentials

Pass an app password (basic authentication) or a bearer token to your client. Do not write it to disk, do not log it, and remove the `Authorization` header from any error you report.
