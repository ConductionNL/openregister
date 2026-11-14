# A form on an approval position

An `approvers` entry of `x-openregister-approval-chains` can declare the form its approval task presents. It is the declaration a flow user-task step uses (`flow-task-forms`):

```json
"x-openregister-approval-chains": {
  "budget": {
    "transition": "approve",
    "approvers": [
      {
        "role": "budgethouder",
        "form": {
          "kind": "fields",
          "fields": [
            { "name": "goedgekeurdBedrag", "required": true, "order": 1 },
            { "name": "voorwaarde", "order": 2 }
          ],
          "requireChecklist": false
        }
      },
      { "role": "controller", "form": { "kind": "external", "formId": 12 } }
    ]
  }
}
```

- `kind` is `fields` (properties of this schema, listed under `fields` or inherited from a lifecycle `action`) or `external` (a Nextcloud Forms form, `formId`).
- A field entry is `{name, required, order}`. `field` is accepted for `name`. Entries are asked for in `order`; entries without an order follow in the order listed.
- `schema` defaults to the schema the chain is declared on.

## What happens

- **Schema save.** The schema API answers 422 when a position's form names a field that is not a property of the schema, is read-only or is not visible, or is malformed. Each refusal names the chain, the position, the field and the reason. These are the same rule and reasons as a flow step's form.
- **Compile.** The position carries the form in the task form record shape. A chain without forms keeps template version 1, and a chain with forms gets a version derived from its forms, so changing a form changes the version.
- **Provision.** Every task created for the position carries the form under `metadata.form`. `TaskService::import()` refuses a form that cannot be rendered, and completing the task goes through the form path, so a missing required field is refused by name.
- **Open tasks.** The form is copied onto the task, so an open task keeps the form it was opened with after the chain changes. The next approval request gets the new form.

A position without `form` creates the same task as before.

Spec: `openspec/changes/approval-position-task-form`.
