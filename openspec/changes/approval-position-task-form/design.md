# Design: approval-position-task-form

No screen; the approver's form is the existing task form, drawn by nextcloud-vue. OpenRegister is not one of the canvas apps.

## D-1: the declaration shape is flow-task-forms'

`form` on an `approvers` entry is exactly what a user-task step declares: `{kind: "fields", fields: [{name, required, order}], requireChecklist}` or `{kind: "external", formId}`. `TaskFormReader` parses it, so a chain and a flow cannot drift into two dialects.

## D-2: refused at schema save, not at task creation

The installer validates at schema save time (it already validates and reports there). A field that is not a property of the schema, is read-only or is not visible refuses the save naming the chain, the position, the field and the reason. Refusing only at `import()` would let the schema save and break the first approval request, in front of the requester, not the author.

## D-3: freezing

The template is a pure function of the schema; the form is part of the compiled position, so a different form is a different template version. `provision()` copies the form into `metadata.form` and `templateSnapshot`. Open tasks keep their form when the schema changes. A field the live schema dropped since shows as broken at completion (`flow-task-forms`), never silently omitted.
