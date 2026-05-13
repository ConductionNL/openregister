---
sidebar_position: 2
title: Create your first register
description: Create a register, give it a name and slug, and confirm it shows in the Registers list.
---

# Create your first register

A *register* is a container that holds related objects of one or more schemas — think *cars*, *contracts*, *publications*, *anything*. Open Register stores everything inside one. This tutorial creates an empty register so you can attach schemas and objects in the next steps.

## Goal

By the end you will have one register in the **Registers** list with a name, a slug, a description, and a *Cards / Table* view that opens cleanly.

## Prerequisites

- Open Register opened and the Registers list reachable (see [Open the Register app for the first time](01-first-launch.md)).
- The right to create registers — by default, any authenticated user can create their own. On an instance with the [RBAC setup](../admin/01-permissions-rbac.md) tightened, you need the *register-admin* role.

## Steps

1. Open **Registers** in the navigation. The list shows the existing registers (or *No registers found* on a fresh install). Click **Add Register**. The *Create Register* dialog opens.

   ![Create Register dialog open](/screenshots/tutorials/user/02-create-a-register-01.png)

2. Fill in the fields — **title** (free-text, shown in the list), **slug** (URL-safe identifier, auto-suggested from the title — keep it short, lowercase, hyphenated), **description** (one or two lines on what this register is for), and an optional **owner** organisation. Pick a colour / icon if you want the register to stand out in the dashboard.

   ![Register fields filled in](/screenshots/tutorials/user/02-create-a-register-02.png)

3. Click **Save**. The dialog closes and the new register appears in the list — switch the *Cards / Table* toggle to compare layouts. The right-hand statistics sidebar now shows `1` more register.

   ![New register in the list](/screenshots/tutorials/user/02-create-a-register-03.png)

4. Click the register card (or row) to open the **Register detail** page. The detail page carries tabs for **Overview**, **Schemas**, **Objects**, **Files**, **Audit Trails**, and **Settings**. Right now every tab is empty — that's expected, no schemas are attached yet.

   ![Register detail page](/screenshots/tutorials/user/02-create-a-register-04.png)

5. Note the register's URL — `/apps/openregister/registers/<id>`. That's a permalink you can share or bookmark.

   ![Register URL in the address bar](/screenshots/tutorials/user/02-create-a-register-05.png)

## Verification

A new register appears in **Registers** with the title and slug you set. Opening it shows the detail page with all six tabs, the statistics sidebar count goes up by one, and the audit-trail tab logs a `create` entry for the register itself.

## Common issues

| Symptom | Fix |
|---|---|
| **Save** is greyed out | A required field is empty — *title* and *slug* are both mandatory. Slug must be `[a-z0-9-]+`. |
| "Slug already in use" error | Another register on the same instance has that slug. Slugs are unique per instance — pick a different one. |
| The new register isn't visible to other users | Default visibility is private to the creator — see [Set up roles and permissions](../admin/01-permissions-rbac.md) to widen access. |

## Reference

- [Attach a schema to your register](03-create-a-schema.md) — what to do next.
- [Add your first object](04-create-an-object.md) — the typical end-to-end flow.
- [Set up roles and permissions](../admin/01-permissions-rbac.md) — who can create / read / write each register.
