---
sidebar_position: 1
title: Set up roles and permissions
description: Configure role-based access control so users can only see and edit the registers they should.
---

# Set up roles and permissions

Open Register ships with role-based access control (RBAC): roles are bundles of permissions, users / groups are assigned roles, and registers and schemas are scoped per organisation. This tutorial walks through one realistic setup — *readers*, *editors*, *admins* — for one register.

## Goal

By the end you will have three roles configured, one user / group assigned per role, and the register's visibility narrowed to those three groups.

## Prerequisites

- Admin rights on the Open Register app — you need the *register-admin* role or Nextcloud admin to edit RBAC.
- A register to lock down (see [Create your first register](../user/02-create-a-register.md)) and at least two Nextcloud users / groups to assign — `editors` and `readers` are reasonable.

## Steps

1. Open the register's detail page and switch to the **Settings** tab. The tab lists the register's metadata at the top and the **Access control** block underneath — *Visibility*, *Roles*, *Default role for new users*.

   ![Register Settings tab, Access control block](/screenshots/tutorials/admin/01-permissions-rbac-01.png)

2. Set **Visibility** to *Private* (only assigned users / groups), *Internal* (any logged-in user on the instance), or *Public* (anyone, including unauthenticated). For this walkthrough pick *Private*.

   ![Visibility set to Private](/screenshots/tutorials/admin/01-permissions-rbac-02.png)

3. Under **Roles**, click **Add role**. The dialog asks for a **role name** (free-text), a **set of permissions** (`read`, `create`, `update`, `delete`, `import`, `export`, `admin`), and an optional **scope** (per-schema, per-object filter). Add three: **reader** (`read`), **editor** (`read`, `create`, `update`), **admin** (everything).

   ![Three roles configured](/screenshots/tutorials/admin/01-permissions-rbac-03.png)

4. Switch to **Members** in the same Settings tab. Click **Add member**, search for a Nextcloud user or group, pick a role from the dropdown, confirm. Add at least one entry per role. Members can be Nextcloud users, Nextcloud groups, or Open Register organisations.

   ![Members assigned](/screenshots/tutorials/admin/01-permissions-rbac-04.png)

5. Save the Settings tab. Log out and log in as a user that should be a *reader* — the register opens read-only, **Add Object** is disabled, the **Files** tab is view-only. Repeat as an *editor* — full CRUD on objects, but the Settings tab is hidden.

   ![Reader-view register opened by a non-admin user](/screenshots/tutorials/admin/01-permissions-rbac-05.png)

## Verification

The Settings tab lists three roles with the right permission bundles, three members assigned to those roles; *readers* see the register but cannot edit; *editors* can edit objects but not change Settings; *admins* see everything. The register no longer appears at all for users outside the three groups.

## Common issues

| Symptom | Fix |
|---|---|
| New member doesn't see the register at all | The user's groups don't include the assigned group, or the user is *Disabled* in Nextcloud — check **Settings → Accounts** in Nextcloud. |
| Editor can read but every save fails with "403" | The role has `update` but the schema is set to *system / read-only* — open the schema and toggle *Read-only* off. |
| Admin can't reach the Settings tab | The admin user's role is missing the `admin` permission — re-open the role and tick it. |

## Reference

- [Access control feature reference](../../Features/access-control.md) — the full permission matrix and how scopes work.
- [Sync data from external sources](02-data-sources-sync.md) — sources also inherit register-level RBAC.
- [Manage admin settings](03-admin-settings.md) — instance-wide defaults.
