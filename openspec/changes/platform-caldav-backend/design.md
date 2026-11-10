# Design: platform-caldav-backend

## D-1. One generator, two surfaces

The feed and the platform calendar must never show different dates for the
same object, because the first person to notice will be the one who missed
a term. Both render from the one event generator wave 1 specifies.

## D-2. Read-only, for the same reason the feed is

An event a client edits would be overwritten at the next generation, and
the caseworker would believe the edit held. Until
`calendar-change-recomputes-timers` lands, the calendar reads and does not
write, which is D11's argument applied to the second surface.

## D-3. Per principal, through the object rules

The platform asks the provider for a principal's calendars. The answer is
built from the objects that principal may list, through the object access
path, with no second rule. That is also what makes a saved-view calendar
safe to offer.

## D-4. A schema with no declared dates keeps today's behaviour

Existing virtual calendars work. A schema that declares no date kind
renders exactly as it does now, so this change adds surfaces and removes
none.

## D-5. kind

Code, in OpenRegister.
