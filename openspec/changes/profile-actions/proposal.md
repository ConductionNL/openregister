# Profile Actions

## Problem
The OpenRegister user profile system currently provides basic CRUD operations (GET/PUT on `/api/user/me`, POST login/logout) but lacks actionable profile operations that users need in a multi-tenant, organisation-aware environment. Users cannot change their own password, manage their avatar, export their personal data (GDPR), manage notification preferences, view their activity history, manage API tokens, or perform account-level actions like deactivation. These gaps force administrators to handle routine user operations manually and prevent compliance with GDPR data portability requirements (Article 20) and the right to erasure (Article 17).

## Proposed Solution
Extend the UserController and UserService with a comprehensive set of profile action endpoints that enable self-service account management. Actions include password change, avatar upload/delete, personal data export (GDPR), notification preferences, activity/audit history for the current user, API token management for programmatic access, and account deactivation request. Each action validates permissions against Nextcloud backend capabilities (e.g., `canChangePassword()`) and respects organisation-level policies. The frontend gains a "Mijn Account" (My Account) page with sections for each action category, using Nextcloud Vue components and NL Design System tokens for consistent styling.
