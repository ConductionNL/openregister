# The OR Dashboard reads its search statistics and popular terms together

## What was wrong

`DashboardIndex.loadSearchTrailData()` awaited `GET /api/search-trails/statistics`
before it sent `GET /api/search-trails/popular-terms`. The two reads do not depend
on each other. On a full load of the OR Dashboard the statistics read
queues behind the page's other reads. It answered ~1.25 s in, so the popular terms
only started then and were the page's last request (~1.35 s): the page counted as
loaded one round trip later than needed.

## What changes

- Both reads go out at once (`Promise.allSettled`).
- When either fails, the widgets fall back to the empty search-trail defaults as
  before, but only after both have settled, so a late answer cannot overwrite the
  fallback.

## Out of scope

- The dashboard's other reads (charts, statistics, `GET /api/dashboard`): their
  upper bound was measured before (skipping them did not move the page).
