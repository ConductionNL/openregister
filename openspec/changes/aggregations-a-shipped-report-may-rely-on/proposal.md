# The aggregations a shipped report may rely on

## Why
launchpad ships a report library (`lib/Settings/reports.json`, change `dashboards-and-who-may-see-them`): versioned
report definitions that count objects of a register and schema. Today its `ReportLibrary` reads the rows through the
object service and counts them in PHP, which is the pattern ADR-022 rules out and which stops working past one page of
objects. launchpad asked (its tasks 9.2 and `competitor-parity-2026-09` 2.2) which aggregations a report that ships
with an app may rely on, so the report library stops querying objects directly. This change is the answer, written as
a contract openregister keeps.

The two kinds launchpad ships today:
- `count-by`: the number of objects per value of one property, with the objects that have no value counted apart
  (`open-cases-by-handler`, `dashboards-by-type`).
- `area`: the number of objects per area property, inside an optional date range on a date property, with the share
  of each and the objects without a location counted apart (`cases-per-area`).

## What changes
- A named subset of the ad-hoc aggregation primitive (`aggregation-api`) becomes the stable report contract: a count,
  grouped by one field, filtered with the `findAll()` vocabulary, bounded by `from`/`to` on a date field, with the
  objects whose group field is empty returned as their own bucket instead of dropped.
- The contract is reachable in-process (`AggregationRunner::runAdhocByRef()`) and over REST, with the caller's RBAC
  and tenancy applied before any SQL, exactly as `findAll()`.
- Anything outside the subset (sum/avg/min/max, multi-field group-by, cumulative buckets) stays available but carries
  no promise for shipped reports.
- A change to the subset's request or response shape is a breaking change for openregister and follows its
  deprecation rule.

## Not in this change
Code. The primitive exists; this change names the part of it a shipped report may build on, adds the empty-value
bucket if a review shows it is missing, and adds a contract test. launchpad moves its `ReportLibrary` onto it in its
own change once this lands.

Handed over from launchpad (`dashboards-and-who-may-see-them` 9.2, `competitor-parity-2026-09` 2.2), 19 Nov 2026.
