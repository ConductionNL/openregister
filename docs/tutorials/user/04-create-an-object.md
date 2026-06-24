---
sidebar_position: 4
title: Add your first object
description: Create an object against a schema, fill in its fields, save it, and confirm it lands in the register.
---

# Add your first object

An *object* is one row of data — one car, one contract, one publication. It lives inside a register and conforms to one of the schemas you attached. This tutorial creates a single object end-to-end.

## Goal

By the end you will have one object stored in your register, visible in the **Search / views** list for that register and schema.

## Prerequisites

- A register with at least one schema attached (see [Create and attach a schema](03-create-a-schema.md)).
- Write permission on the register — the creator has it by default; other users need an entry in the [permissions setup](../admin/01-permissions-rbac.md).

## Steps

1. Open **Search / views** from the app navigation — this is where you browse and create objects. Pick a **Register** and a **Schema** in the sidebar; the object list for that register + schema loads (or *No objects found* on an empty one).

   > **Shortcut:** from a register's detail page (**Registers → your register**) open a schema's **⋯ → View objects**. That lands you here with the register and schema already selected.

   ![Search / views with a register and schema selected](/screenshots/tutorials/user/04-create-an-object-01.png)

2. Click **Add Object**. The create dialog renders a form generated straight from the schema. Each property becomes one field, typed correctly — strings get a text input, dates get a date picker, enums get a dropdown, booleans get a checkbox. Required fields are marked.

   ![Schema-driven object create form](/screenshots/tutorials/user/04-create-an-object-02.png)

3. Fill the form in. For the schema from the previous step that's *title* (required), *description*, *status* (pick *draft*), *date* (today). Click **Save**.

   ![Form filled in](/screenshots/tutorials/user/04-create-an-object-03.png)

4. The dialog closes and the object detail page opens. The sidebar carries **Overview**, **Properties**, **Files**, **Comments**, **Audit Trails**, **Related** and **Source** tabs — each one is empty for now except *Properties* (your data) and *Audit Trails* (a `create` entry).

   ![Object detail page](/screenshots/tutorials/user/04-create-an-object-04.png)

5. Go back to **Search / views** (with the same register + schema selected). Your new object appears as one row, with the schema title rendered as a chip, the *status* enum value, and a timestamp.

   ![Search / views with the new object row](/screenshots/tutorials/user/04-create-an-object-05.png)

## Verification

The object appears in the **Search / views** list for that register + schema, the **Properties** tab shows the values you typed, the **Audit Trails** tab has a `create` entry timestamped now, and the register's object counter in the statistics sidebar goes up by one.

## Common issues

| Symptom | Fix |
|---|---|
| **Add Object** is disabled | No register **and** schema is selected in the **Search / views** sidebar — pick both. (If the register has no schema attached at all, see [Create and attach a schema](03-create-a-schema.md).) |
| Form opens but every field shows "unknown type" | The attached schema's *Source* tab has invalid JSON Schema — fix it before adding objects. |
| Save fails with "validation failed: property X" | The value you typed doesn't match the schema rule for that property (required-but-empty, wrong enum value, regex mismatch). The error message names the offending property. |
| Object disappears after a refresh | You did not have write rights on the register — the dialog let you fill the form but the save was rejected. Check the [RBAC setup](../admin/01-permissions-rbac.md). |

## Reference

- [Find an object with search and filters](05-search-and-filter.md) — next step.
- [Object storage feature reference](../../features/object-storage.md) — how Open Register stores objects internally.
- [Object interactions feature reference](../../features/object-interactions.md) — relations, tags, links between objects.
