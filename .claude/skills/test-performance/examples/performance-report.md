<!-- Example output — test-performance for OpenRegister (Nextcloud app) -->

## Performance Report: openregister

### Overall: ACCEPTABLE — some optimization needed for large object collections

---

### Page Load Times

| Page | TTFB | DOM Ready | Full Load | Resources | Status |
|------|------|-----------|-----------|-----------|--------|
| Dashboard | 95ms | 680ms | 1,140ms | 42 | GOOD |
| Registers list (12 registers) | 110ms | 720ms | 1,280ms | 44 | GOOD |
| Schema detail (8 fields) | 128ms | 890ms | 1,620ms | 47 | ACCEPTABLE |
| Objects list (500 objects) | 142ms | 1,840ms | 2,910ms | 51 | ACCEPTABLE |
| Objects list (2000 objects) | 148ms | 4,210ms | 5,890ms | 51 | POOR |
| Settings | 88ms | 590ms | 980ms | 40 | GOOD |

**Note**: Objects list degrades significantly at 2000 objects — client-side rendering all rows in the DOM.

---

### API Response Times

| Endpoint | Method | Avg (ms) | Min (ms) | Max (ms) | Status |
|----------|--------|----------|----------|----------|--------|
| /api/registers | GET | 48ms | 41ms | 68ms | GOOD |
| /api/registers/{id} | GET | 32ms | 28ms | 45ms | GOOD |
| /api/registers | POST | 67ms | 58ms | 91ms | GOOD |
| /api/schemas | GET | 52ms | 44ms | 71ms | GOOD |
| /api/objects/{r}/{s} (limit=20) | GET | 89ms | 74ms | 124ms | GOOD |
| /api/objects/{r}/{s} (limit=100) | GET | 312ms | 287ms | 398ms | ACCEPTABLE |
| /api/objects/{r}/{s} (limit=500) | GET | 1,840ms | 1,712ms | 2,011ms | POOR |
| /api/objects/{r}/{s} (search) | GET | 2,100ms | 1,940ms | 2,380ms | POOR |

**Object search is the main bottleneck**: full-text search scans all objects client-side.

---

### Load Test (20 sequential requests to GET /api/registers)

| Endpoint | Avg (ms) | Degradation | Errors | Status |
|----------|----------|-------------|--------|--------|
| /api/registers | 51ms | +8% (within noise) | 0 | STABLE |
| /api/objects/{r}/{s}?limit=100 | 334ms | +7% | 0 | STABLE |

---

### Container Resources

| Metric | Idle | Under Load (20 seq requests) | Status |
|--------|------|------------------------------|--------|
| CPU | 1.2% | 18.4% | OK |
| Memory | 312MB | 338MB | OK |
| Net I/O (20 reqs) | — | 4.2MB / 1.8MB | OK |

---

### N+1 Query Analysis

**Dashboard load**: 3 API calls (registers, schemas, objects count) — acceptable.

**Objects list load**: 1 API call for collection — correct, no N+1 detected.

**Schema detail**: 2 API calls (schema + properties) — acceptable.

---

### Frontend Bundle Analysis

| Script | Transfer Size | Load Time | Notes |
|--------|--------------|-----------|-------|
| openregister-main.js | 2.4MB | 840ms | Heavy — includes schema editor bundled unconditionally |
| vue-vendor.js | 1.1MB | 380ms | Shared Vue vendor bundle |
| nextcloud-commons.js | 280KB | 95ms | Nextcloud shared components |

**Total JS**: 3.8MB — exceeds recommended budget. Schema editor (heaviest module) loads on every page even when not needed.

---

### Bottlenecks Found

| # | Type | Location | Impact | Suggestion |
|---|------|----------|--------|------------|
| 1 | render | Objects list DOM | POOR at 2000+ objects | Implement virtual scrolling or server-side pagination (max 50 rows in DOM) |
| 2 | query | Object search API | 2.1s for full-text search | Add database index on searchable fields; implement server-side search instead of client-side filter |
| 3 | bundle | openregister-main.js 2.4MB | 840ms first load | Code-split schema editor — lazy load only when user navigates to schema editing |
| 4 | api | GET /api/objects limit=500 | 1.8s | Enforce max page size of 100; document pagination to discourage large fetches |

---

### Slowest Resources

| Resource | Duration | Size |
|----------|----------|------|
| openregister-main.js | 840ms | 2.4MB |
| vue-vendor.js | 380ms | 1.1MB |
| /api/objects (limit=500) | 1,840ms | 2.1MB |

---

### Recommendation

**OPTIMIZE BEFORE RELEASE**

The 2000-object list view and search API response times exceed acceptable thresholds for production use. The 3.8MB JS bundle will cause noticeably slow first loads on mobile/slow connections. Address bottlenecks #1 and #2 before release; #3 and #4 can follow in the next iteration.

```
PERFORMANCE_TEST_RESULT: FAIL  CRITICAL_COUNT: 2  SUMMARY: Object list renders poorly at 2000+ items (5.9s load), search API takes 2.1s — server-side pagination and search indexing needed before release
```
