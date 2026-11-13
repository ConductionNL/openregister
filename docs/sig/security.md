---
title: "Security questionnaire"
description: "Questions and answers on the data flow, connections, data stores and security controls of OpenRegister, for a security assessment."
sidebar_position: 3
---

# Security questionnaire

Our answers to the high-level description questionnaire for a security assessment. Each row quotes the question, answers it briefly and links where the subject is written up.

OpenRegister builds on Nextcloud's security instead of its own. Nextcloud handles login, sessions, CSRF, rate limits, encryption and secrets; OpenRegister adds schema and register authorisation, multi-tenancy and the audit trail on Nextcloud users and groups. The [overview](./index.md#nextcloud-is-the-framework) explains why, and [building on Nextcloud's security](https://docs.conduction.nl/iso/security/#we-build-on-nextclouds-security) states it for every Conduction app.

Report a vulnerability as described in [SECURITY.md](https://github.com/ConductionNL/openregister/blob/main/SECURITY.md) and our [security policy](https://docs.conduction.nl/iso/security/), never in a public issue.

## General information

| # | Question | Our answer | Where |
|---|---|---|---|
| 1 | "Your name" | Given on the submitted form. | |
| 2 | "Title" | Given on the submitted form. | |
| 3 | "Company" | Conduction B.V. | `appinfo/info.xml` `<author>` |
| 4 | "System name" | OpenRegister, Nextcloud app id `openregister`. | `appinfo/info.xml`; [Introduction](https://openregister.conduction.nl/docs/intro/) |
| 5 | "Programs: programming language, programming framework and program type (e.g. Web application back-end, web application front-end, mobile application, desktop application, web service, auxiliary tool, deployment script, other (please specify))" | A PHP back end and web service (REST, GraphQL, MCP), a Vue front end, three static browser scripts, cron jobs and `occ` commands, and migrations. Development tooling is not shipped. | `lib/`, `src/`, `js/`, `lib/BackgroundJob/`, `lib/Command/`, `lib/Migration/`; `ARCHITECTURE.md` (directory roles) |

## Technologies

| # | Question | Our answer | Where |
|---|---|---|---|
| 6 | "Main programming language(s) used" | PHP 8.3, TypeScript and JavaScript. | `composer.json`; `package.json` |
| 7 | "Other programming languages" | SQL through the Nextcloud QueryBuilder, Twig, JSON Schema, and shell and Python for tooling only. | `lib/Db/`; `scripts/` |
| 8 | "Database technology" | The Nextcloud database: PostgreSQL, MySQL, MariaDB or SQLite. Solr or Elasticsearch are optional search indexes. | `appinfo/info.xml` `<database>`; [Object storage](https://openregister.conduction.nl/docs/features/object-storage/) |
| 9 | "GUI technology" | Vue 3.5 with Pinia, @nextcloud/vue and @conduction/nextcloud-vue, inside the Nextcloud web interface. | `package.json`; [Frontend architecture](https://openregister.conduction.nl/docs/development/frontend/) |
| 10 | "Security-specific framework(s)/libraries" | Nextcloud's security middleware, `ICrypto`, `ICredentialsManager`, `ISecureRandom` and `IHasher`. On top: web-token/jwt-library and phpseclib for JWTs, libsodium for signed bundles, the Twig sandbox and DOMPurify. | `composer.json`; `lib/Service/Consumer/JwtValidator.php`; `lib/Service/Config/BundleSigner.php` |

## System and high-level design

| # | Question | Our answer | Where |
|---|---|---|---|
| 11 | "Please describe the overall functionality of the system" | The data layer of Conduction's Nextcloud apps, mostly for Dutch public bodies. Objects can hold personal data such as names, addresses and citizen service numbers (BSN). | [Feature overview](https://openregister.conduction.nl/docs/features/openregister-overview/); `lib/Service/FieldEncryptionHandler.php` |
| 12 | "Data Flow Diagram" | Three zones: untrusted clients, the hosting organisation's Nextcloud server, and optional third-party services. Every crossing is HTTPS, and Nextcloud authenticates a request before OpenRegister runs. | `ARCHITECTURE.md` (system boundary, request flow); `appinfo/routes.php` |
| 13 | "List inbound connections" | The web interface, REST, GraphQL and MCP APIs, public endpoints, access links, DAV and federation. All 77 public controller methods carry a rate-limit or brute-force attribute (checked 9 November 2026). | `appinfo/routes.php`; `lib/Service/AuthorizationService.php`; [Objects API](https://openregister.conduction.nl/docs/api/objects/); [Access links](https://openregister.conduction.nl/docs/features/access-links/) |
| 14 | "List outbound connections" | Only services an administrator configures: search, LLM, extraction, webhooks, sync sources, GitHub, OAuth2, web push. Every call goes through `OutboundHttpClient` on Nextcloud's `IClientService`, which refuses local addresses. | `lib/Service/Outbound/OutboundHttpClient.php`; [Webhooks and notifications](https://openregister.conduction.nl/docs/features/webhooks-and-notifications/) |
| 15 | "List data stores" | The Nextcloud database, file storage, credential vault and memory cache, plus optional search indexes and an audit sink file. Fields marked `x-openregister-encrypted` are encrypted with `ICrypto`. | `lib/Service/FieldEncryptionHandler.php`; `lib/Service/Credential/NextcloudVaultCredentialStore.php`; [Object storage](https://openregister.conduction.nl/docs/features/object-storage/) |

## Security controls

| # | Question | Our answer | Where |
|---|---|---|---|
| 16 | "Please describe the authentication and use of sessions in the system" | Nextcloud authenticates every user and owns the session, including SSO and second factor. API consumers use app passwords, OAuth2 or a configured JWT or API key, mapped to a Nextcloud user. | `lib/Service/AuthorizationService.php`; `lib/Middleware/RateLimitMiddleware.php`; [Access links](https://openregister.conduction.nl/docs/features/access-links/); [Security review checklist](https://docs.conduction.nl/claude/security-review-checklist/) |
| 17 | "Please describe the authorization mechanism(s) of the system" | RBAC on Nextcloud groups per schema, register, object and property, with organisation-based multi-tenancy. The server checks every rule through one evaluator, `PermissionHandler`. | `lib/Service/Object/PermissionHandler.php`; `lib/Service/Rbac/`; [Roles and permissions](https://openregister.conduction.nl/docs/tutorials/admin/permissions-rbac/); [Property authorization](https://openregister.conduction.nl/docs/Features/property-authorization/); [Multi-tenancy](https://openregister.conduction.nl/docs/Features/multi-tenancy/) |
| 18 | "Please describe the used cryptographic techniques and algorithms of the system" | We reuse Nextcloud's crypto (`ICrypto`, AES-256-CBC with HMAC-SHA512) and well-known libraries, and implement no algorithm ourselves. Webhooks are signed with HMAC-SHA256, bundles with Ed25519, and the audit trail is a SHA-256 hash chain. | `lib/Service/FieldEncryptionHandler.php`; `lib/Service/WebhookRequestOptions.php`; `lib/Service/AuditHashService.php`; [Credential broker](https://openregister.conduction.nl/docs/Features/credential-broker/) |
| 19 | "Please describe the strategy to prevent injection attacks and other data handling attacks" | Objects are validated against their JSON Schema, SQL uses bound parameters, user templates run in a Twig sandbox, and uploads are checked for executable content. Vue escapes output, and the one HTML render goes through DOMPurify. | `lib/Service/Object/ValidateObject.php`; `lib/Db/MagicMapper.php`; `lib/Service/File/ExecutableContentDetector.php`; [Executable file blocking](https://openregister.conduction.nl/docs/development/security-architecture/); [Validation errors](https://openregister.conduction.nl/docs/Features/enhanced-validation-errors/) |
| 20 | "Please describe configured security mechanisms" | Nextcloud sets the security headers, and secrets live encrypted or in the credential vault. OpenRegister ships no production configuration; the Docker Compose files are for development only. | `lib/Middleware/PublicApiCorsMiddleware.php`; `lib/Service/TenantLogRedactor.php`; [Instance hardening](https://openregister.conduction.nl/docs/features/instance-hardening/) |
| 21 | "Please describe the strategy for security logging" | Every object change and access change goes to the audit trail, sealed into a SHA-256 hash chain and optionally shipped to a SIEM. Log lines carry no secrets. | `lib/Service/AuditHashService.php`; `lib/BackgroundJob/AuditSealJob.php`; `lib/Service/Audit/AuditSink.php`; [Versioning and audit trail](https://openregister.conduction.nl/docs/features/versioning-and-audit/) |
| 22 | "Please describe the dependency management strategy of the system" | Composer and npm with committed lock files, weekly Dependabot updates with cooldowns, and `composer audit` on every pull request. Every release ships a CycloneDX SBOM that Grype scans. | `.github/dependabot.yml`; `.npmrc`; `SECURITY.md`; [Development pipeline](https://docs.conduction.nl/WayOfWork/development-pipeline/); [Information security policy](https://docs.conduction.nl/iso/security-policy/) |

## Other remarks

| # | Question | Our answer | Where |
|---|---|---|---|
| 23 | "Please use the space below for any additional remarks" | The app serves `/.well-known/security.txt`. Company-wide ADRs record the security rules, and the Hydra gates check several of them on every pull request. | `lib/Controller/WellKnownController.php`; [Security policy](https://docs.conduction.nl/iso/security/); [Overview: how we specify and decide](./index.md#how-we-specify-and-decide) |
| 24 | "How much time was spent filling in this form? Other feedback?" | Given on the submitted form. | |

See also the [maintainability questionnaire](./maintainability.md).
