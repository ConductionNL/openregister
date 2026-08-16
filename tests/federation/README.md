# OpenCatalogi Federation Integration Tests

Automated tests for OpenCatalogi's federation features: directory sync, listing management, federated search, broadcast/anti-loop protection, and cross-catalog publication aggregation.

## How It Works

The test suite spins up **two isolated Nextcloud instances** (with a shared PostgreSQL database) in Docker, installs OpenRegister + OpenCatalogi on both, runs a comprehensive Newman test collection, then tears everything down. Nothing persists after the test — no leftover containers, volumes, or networks.

## Quick Start

```bash
cd openregister
bash tests/federation/run-federation-tests.sh
```

## Requirements

- Docker + Docker Compose v2
- Node.js / npm (for `npx newman`)
- Ports 9081 and 9082 available (only used during the test run)

## What Gets Tested

### Phase 1: Health & Initialization
- Both Nextcloud instances are healthy and responding
- OpenRegister API is active on both instances
- OpenCatalogi directory endpoint is active on both instances

### Phase 2: Setup Catalogs & Publications
- Get OpenCatalogi configuration from Instance 2
- Create a catalog on Instance 2
- Create a register + schema on Instance 2
- Create two test publications on Instance 2
- Verify Instance 2's directory shows its catalog

### Phase 3: Directory Registration & Sync
- Verify Instance 1 starts with no/minimal listings
- Register Instance 2's directory URL on Instance 1
- Verify listings appear after registration
- Get individual listing details
- Explicit directory sync
- Verify directory includes Instance 2 entries

### Phase 4: Listing CRUD
- Create a manual listing
- Update the listing
- Read the updated listing
- Delete the listing
- Confirm deletion (404)

### Phase 5: Federated Search & Publications
- Aggregated federated publications list
- Keyword search across federated catalogs
- Pagination on federated search
- Search endpoint (separate from federation)
- Direct access to Instance 2's publications

### Phase 6: Bidirectional Federation & Anti-Loop
- Register Instance 1 as directory on Instance 2
- Verify Instance 2 has listings from Instance 1
- Bidirectional sync from Instance 1 (no infinite loop)
- Bidirectional sync from Instance 2 (no infinite loop)
- Federated search from Instance 2's perspective

### Phase 7: Integration Level & Availability
- Set listing integration level to `none`
- Verify disabled listing is excluded from federated search
- Re-enable listing integration level to `search`

### Phase 8: Catalog CRUD
- Create catalog on Instance 1
- List catalogs
- Get single catalog

### Phase 9: Final State Verification
- Final listing counts on both instances
- Final directory state

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                federation-test-network               │
│                                                      │
│  ┌──────────┐    ┌──────────┐    ┌──────────────┐   │
│  │ nc-fed-1 │    │ nc-fed-2 │    │ federation-db│   │
│  │ :9081    │◄──►│ :9082    │    │ PostgreSQL   │   │
│  │          │    │          │    │ nc1 + nc2 DBs│   │
│  └────┬─────┘    └────┬─────┘    └──────┬───────┘   │
│       │               │                │            │
│       └───────────────┴────────────────┘            │
└─────────────────────────────────────────────────────┘
```

- **nc-fed-1** (port 9081): The "home" catalog that discovers and searches others
- **nc-fed-2** (port 9082): The "remote" catalog with test publications
- **federation-db**: Shared PostgreSQL with two databases (`nc1`, `nc2`)
- All on an isolated Docker network (`federation-test-network`)

## Files

| File | Purpose |
|------|---------|
| `docker-compose.federation.yml` | Two Nextcloud instances + shared DB |
| `tests/federation/federation-tests.postman_collection.json` | Newman collection (9 phases, 30+ requests) |
| `tests/federation/run-federation-tests.sh` | Orchestration: start, install, test, teardown |
| `tests/federation/init-second-db.sql` | Creates the second PostgreSQL database |
| `tests/federation/README.md` | This file |

## Running Individual Phases

You can run the Newman collection manually against already-running instances:

```bash
npx newman run tests/federation/federation-tests.postman_collection.json \
    --env-var "nc1Url=http://localhost:9081" \
    --env-var "nc2Url=http://localhost:9082" \
    --env-var "nc2Internal=http://nc-fed-2" \
    --folder "Phase 1: Health & Initialization"
```

## Troubleshooting

### Containers fail to start
- Check if ports 9081/9082 are in use: `ss -tlnp | grep 908`
- Check Docker logs: `docker logs nc-fed-1` / `docker logs nc-fed-2`

### Apps fail to enable
- Check the Nextcloud log: `docker exec nc-fed-1 cat /var/www/html/data/nextcloud.log | tail -20`
- Ensure the OpenRegister and OpenCatalogi source directories are present in the parent directory

### Directory sync doesn't find remote instance
- The `isLocalUrl()` filter in DirectoryService excludes localhost/private IPs, but Docker hostnames (`nc-fed-2`) pass through correctly
- Check inter-container connectivity: `docker exec nc-fed-1 curl -s http://nc-fed-2/status.php`

### Tests timeout
- First run takes longer due to Nextcloud installation (can be 2-3 minutes per instance)
- Increase `MAX_WAIT` in the shell script if needed
