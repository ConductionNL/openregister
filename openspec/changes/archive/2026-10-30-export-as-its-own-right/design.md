# Design: export-as-its-own-right

## D-1. Export is a verb, because hiding the button is not a control

An export gated by hiding a menu item is an export anybody can take
through the API. The verb is evaluated in the authorization layer, on
every path, which is the only place a control like this survives contact
with an integration.

## D-2. The verb defaults to granted on upgrade

Shipping a new verb that defaults to denied breaks every instance on the
morning of the upgrade. It defaults to granted, and an administrator
narrows it. The migration says so out loud, because a security control
that arrives silently gets turned off in a hurry.

## D-3. A profile is an object, not a query string

A field set that lives in a URL cannot be reviewed, cannot be shared, and
changes whenever somebody edits a screen. As an object it has an owner, a
history and a name an administrator can point at in a procedure.

## D-4. Stored or rendered is one choice per profile, written in the file

A file that mixes raw codes and rendered labels is a file somebody has to
guess about. The profile chooses once, and the export's own metadata says
which mode produced it, so the receiving system does not have to infer it.

## D-5. The whole-set extract is a bulk job, not a bigger export

A datawarehouse extract runs for minutes and touches every register. That
is exactly what `bulk-action-jobs` already specifies, including progress,
skips and a per-row outcome. Writing a second long-running path here would
be a second answer to a solved problem.

## D-6. Every export is on the audit trail

An export is the moment data leaves. Recording the actor, the profile and
the row count is what lets an incident be reconstructed, and it costs one
row per export.

## D-7. kind

Code, in OpenRegister.
