# Tasks: Production Observability

- [ ] Implement: Prometheus Metrics Endpoint
- [ ] Implement: Standard Application Metrics
- [ ] Implement: Register, Schema, and Object Count Metrics
- [ ] Implement: CRUD Operation Counters
- [ ] Implement: Search Performance Metrics
- [ ] Implement: Webhook Delivery Monitoring
- [ ] Implement: Health Check Endpoint
- [x] Implement: Readiness Endpoint
- [ ] Implement: Structured Logging
- [ ] Implement: BIO2 Audit Logging Compliance
- [ ] Implement: Database Connection Monitoring
- [ ] Implement: Alerting Threshold Configuration
- [ ] Implement: Metrics Storage Strategy
- [ ] Implement: Performance Baseline Metrics
- [ ] Implement: Nextcloud Dashboard Integration

## Status, 2026-11-13

**Built: the readiness endpoint.** `GET /api/ready` is served by
`AppHost\Controller\GenericReadinessController`, beside the generic health
controller. It answers 200 `{"ready": true}` once this app's migrations have run
for the deployed code (`IAppManager::isUpgradeRequired()`) and the database
answers (the same `SELECT 1` probe as the health check); otherwise 503
`{"ready": false, "reason": "migrations_pending" | "database_unavailable"}`. A
check that throws counts as not ready. It is public like health (ADR-006) and
returns a reason code only, never an error message or a version.

It is registered on OpenRegister's own route table. Offering it to leaf apps
through `AppHost\Routes::standard()` also needs a leaf controller alias in
`Bootstrap` (see the store-route note there for what a route without its alias
does), so that is a follow-up.

**Already served before this change,** by the AppHost observability engine from
the `observability` block of `src/manifest.json` (ADR-040): the metrics endpoint,
register/schema/object counts, CRUD counters from the audit trail, search request
and webhook delivery counters, and the health endpoint (database, filesystem).
These items are left unticked here because not every scenario of their
requirements was verified against the engine (for example scraper-token and IP
access to the metrics endpoint, request-duration histograms, and the search
backend health check); each needs its own check before it is ticked.

