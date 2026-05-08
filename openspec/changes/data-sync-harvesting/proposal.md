# Data Sync and Harvesting

## Problem
Implement a robust, multi-source data synchronization and harvesting pipeline that enables OpenRegister to pull data from external APIs (REST, OData, SOAP), file feeds (CSV, JSON, XML), other OpenRegister instances, and Dutch government base registrations (BAG, BRK, BRP, HR) into register schemas. The sync pipeline MUST follow CKAN's proven three-stage pattern (gather, fetch, import) with per-record status tracking, support both scheduled (cron) and event-triggered execution, and provide incremental sync via last-modified tracking or change tokens.

## Proposed Solution
Implement a robust, multi-source data synchronization and harvesting pipeline that enables OpenRegister to pull data from external APIs (REST, OData, SOAP), file feeds (CSV, JSON, XML), other OpenRegister instances, and Dutch government base registrations (BAG, BRK, BRP, HR) into register schemas. The sync pipeline MUST follow CKAN's proven three-stage pattern (gather, fetch, import) with per-record status tracking, support both scheduled (cron) and event-triggered execution, and provide increme
