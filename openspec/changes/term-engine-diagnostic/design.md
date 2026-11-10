# Design: term-engine-diagnostic

## D-1: the same code path, narrated

The diagnostic must answer what arming would do, so it calls the same
`SlaCalculator::add()` the arm path calls, with a collector passed in that
records every skipped day and the roll. A separate "explain" implementation
would drift from the real one, which is the failure the row measures.

## D-2: read only, admin only

The endpoint creates no timer, no ledger event and no audit row on any
object. It is `#[NoCSRFRequired]` for the deep link and requires admin,
because the calendar it reads may name an organisation the caller is not in.

## D-3: the walk is a list, not a calendar widget

The output is ordered rows: date, kind (`working`, `weekend`, rule name,
`exception`), and whether it counted. That renders as a plain list and
copies into a letter to a citizen.

## D-4: kind

Code, in OpenRegister.
