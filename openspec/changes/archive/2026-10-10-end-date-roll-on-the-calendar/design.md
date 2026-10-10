# Design: end-date-roll-on-the-calendar

## D-1: a roll is a property of the budget, not of the calendar

The same calendar serves a service standard that may end on a Sunday and a
statutory term that may not. So the option sits on the SLA shape the timer
was armed with, beside `value` and `unit`, and is stored on the timer row
(`roll_to_working_day`). The calendar stays a description of days.

## D-2: applied last, against the resolved calendar

`add()` computes the moment from the budget, then, when the roll is `next`,
walks forward to the first day `isWorkingDay()` accepts, keeping the time of
day. `previous` walks backward. The walk reuses the memoised non-working
dates, so the cost is bounded by the longest holiday cluster.

## D-3: the ledger explains the move

The timer event written at arm time carries `unrolled_at` when the roll
changed the moment and the name of the non-working rule hit
(`Tweede Paasdag`, `weekend`). `describe()` surfaces both. A handler who
sees a term end on 6 April reads that 4 and 5 April were Easter.

## D-4: kind

Code, in OpenRegister. dossiq's half is configuration.
