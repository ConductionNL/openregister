# Design: records-saved-templates

Read at openregister development 0ca409ee04.

## D-1: modelled on saved views

A saved view already solves "a user keeps a named thing and shares it":
`lib/Db/View.php` carries `owner` (:108), `isPublic` (:136) and `sharedWith` (:201),
and `ViewMapper::findAllFor(ViewerReach)` (`lib/Db/ViewMapper.php:312`) lists what a
user may see. A `RecordTemplate` entity takes the same three fields plus `register`,
`schema`, `name`, `description` and `values` (json), in a new table
`openregister_record_templates`, and its mapper lists with the same reach logic.

## D-2: values are data, validated when used

A template stores values, not a half-saved object. When the create dialog applies a
template it drops any value whose property no longer exists or whose type no longer
matches, and says which. The record is then saved through `SaveObject` like any other,
so validation, defaults, calculations and RBAC all apply once. The template is never
a way to write a field the user may not write: property-level authorization is checked
on save, and a template value for a forbidden field is refused there.

## D-3: "Save as template" picks fields

Saving a record as a template does not copy everything. The dialog lists the record's
fields with the identity, dates and relations unticked by default, because those
belong to one record. The user ticks what the template keeps.

## D-4: routes

`/api/record-templates` index, show, create, update, patch and destroy, declared next
to the `/api/views` routes (`appinfo/routes.php:1784-1789`). Index takes `register`
and `schema` filters. Update and destroy are allowed for the owner and administrators;
use is allowed for anyone the reach logic includes who may create in that schema.

## Declarative-vs-imperative decision

Not declarative behaviour on a schema: a template is user data, like a saved view.

## Risks

- A shared template leaking values the recipient may not read: a template carries
  only what its owner typed or chose from a record they could read, and sharing is to
  users who may create in the schema. The picker omits properties the applying user
  may not update.
