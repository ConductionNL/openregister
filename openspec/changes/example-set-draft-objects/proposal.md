## Why

A resident's saved form is a draft: the destination object in status `draft`, owned by `portal:<subjectRef>` (decisions 180 and 195). The portaliq board FormulierHervatten draws a resident reopening such a form, and Mijn zaken lists it as an unfinished form. An example set could not seed one, because its objects carry neither a status nor an owner, so the board could not be measured on a demo instance.

## What Changes

- An object's `data` MAY declare `@self: {status: "draft", owner: "<scope>:<reference>"}`. Placeholders are allowed, for example `"owner": "portal:{{cast:sanne.uid}}"`.
- Install writes the object in status `draft`, owned by that scoped owner, the way `FormSubmitService::saveDraft()` writes a portal draft.
- The check refuses an `@self` with any other key, a status other than `draft`, or an owner that `DraftOwner::scoped()` refuses. Nothing is written when it refuses.
- A draft object is otherwise an ordinary set object: it is kept on a second install and deleted on remove.
