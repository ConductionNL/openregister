# Design: archival-frozen-refuses-delete-and-dates-follow

Read at openregister development 555af7212 and pipelinq development 9a5e95c.

## Context

- `ArchiveHandler::freeze()` (`lib/Service/Object/ArchiveHandler.php:203`)
  writes the `@self.frozen` marker (`by`, `at`, `reason`, `state`) and refuses
  a caller without `update`. `SaveObject` refuses every data write to a frozen
  object (`lib/Service/Object/SaveObject.php:3712`). Nothing in the delete path
  reads the marker.
- Delete guards are listeners on the stoppable `ObjectDeletingEvent`:
  `WorkingCalendarDeleteGuardListener` and `ConceptDeleteGuardListener`
  (`lib/AppInfo/Application.php:3359`, `:3378`).
- `ArchiveActionDateCalculator` knows `ander_datumkenmerk`
  (`sourceDateProperty`, required, `REFUSE_WITHOUT_BRONDATUM` at
  `lib/Service/Archival/ArchiveActionDateCalculator.php:87`) and the relation
  methods (`sourceRelation`, `sourceRelationProperty`, `:268`).
- `RetentionService::recalculateArchiveActionDate()` (`lib/Service/RetentionService.php:289-360`),
  called from `SaveObject.php:6135`, returns early without `archiefnominatie`
  (`:303`) and looks for a changed source only under `eigenschap`
  (`bronEigenschap`) and `afgehandeld` or `termijn` (`closureField`)
  (`:317-336`).

## D-1: the frozen guard

`FrozenObjectDeleteGuardListener` reads `@self.frozen` of the object being
deleted and, when present, stops the event with "This record is locked by
<display name> since <date>. Unlock it before deleting it." It runs for the
single delete, the bulk delete and cascade deletes, because all dispatch the
event. A cascade that meets a frozen child is refused as a whole, as the
existing guards already make it.

## D-2: recalculation per method

`recalculateArchiveActionDate()` compares the source for every method:

- `ander_datumkenmerk`: `sourceDateProperty` old against new;
- the relation methods: `sourceRelation` old against new on this record;
- `eigenschap`, `afgehandeld` and `termijn`: as today.

The `archiefnominatie` early return stays, but a schema whose archive block
declares a `defaultNominatie` fills it when the first date appears, so a
record created without the date gets its nomination and date together later.
A source that becomes empty clears `archiefactiedatum` and records an audit
entry saying the destruction date was removed.

## D-3: dependants follow through a job

When an object is saved and some schema's archive block has a relation method
whose `sourceRelation` points at this object's schema and whose
`sourceRelationProperty` changed, `SaveObject` queues
`RelatedRetentionRecalculationJob` with the object's uuid and the property.
The job finds the dependants through the relation index and recalculates each
through the same method, in batches of 500. The client's own save is not
slowed.

## Risks

- A mass update of clients queues many jobs. The job is deduplicated per
  source uuid.
