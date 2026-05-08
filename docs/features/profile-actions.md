# User Profile & Account Management

| Property   | Value |
|------------|-------|
| Status     | Implemented (500 errors due to OpenConnector dependency) |
| Standards  | GEMMA Identiteitsbeheercomponent, AVG/GDPR (data export, deactivation) |
| App        | OpenRegister |

## Overview

OpenRegister provides self-service account management endpoints under `/api/user/me/*`. Users can manage their profile, password, avatar, notification preferences, activity history, API tokens, data export, and account deactivation. All endpoints require authentication and operate on the currently logged-in user.

## Key Components

| Component | File | Purpose |
|-----------|------|---------|
| `UserController` | `lib/Controller/UserController.php` | REST API for all user management endpoints |
| `UserService` | `lib/Service/UserService.php` | Business logic for user operations |
| `SecurityService` | `lib/Service/SecurityService.php` | Authentication and authorization checks |

## API Endpoints

All endpoints are prefixed with `/index.php/apps/openregister/api/user/me`.

| # | Method | URL | Route Name | Description |
|---|--------|-----|------------|-------------|
| 1 | GET | `/api/user/me` | `user#me` | Get current user profile |
| 2 | PUT | `/api/user/me` | `user#updateMe` | Update user profile |
| 3 | PUT | `/api/user/me/password` | `user#changePassword` | Change password |
| 4 | POST | `/api/user/me/avatar` | `user#uploadAvatar` | Upload avatar image |
| 5 | DELETE | `/api/user/me/avatar` | `user#deleteAvatar` | Remove avatar |
| 6 | GET | `/api/user/me/export` | `user#exportData` | Export user data (GDPR) |
| 7 | GET | `/api/user/me/notifications` | `user#getNotificationPreferences` | Get notification settings |
| 8 | PUT | `/api/user/me/notifications` | `user#updateNotificationPreferences` | Update notification settings |
| 9 | GET | `/api/user/me/activity` | `user#getActivity` | Get activity history |
| 10 | GET | `/api/user/me/tokens` | `user#listTokens` | List API tokens |
| 11 | POST | `/api/user/me/tokens` | `user#createToken` | Create new API token |
| 12 | DELETE | `/api/user/me/tokens/{id}` | `user#revokeToken` | Revoke an API token |
| 13 | POST | `/api/user/me/deactivate` | `user#requestDeactivation` | Request account deactivation |
| 14 | GET | `/api/user/me/deactivation-status` | `user#getDeactivationStatus` | Check deactivation status |
| 15 | DELETE | `/api/user/me/deactivate` | `user#cancelDeactivation` | Cancel pending deactivation |

### Additional Auth Endpoints

| Method | URL | Route Name | Description |
|--------|-----|------------|-------------|
| POST | `/api/user/login` | `user#login` | User login |
| POST | `/api/user/logout` | `user#logout` | User logout |

## GDPR / AVG Compliance

- **Data Export** (`GET /api/user/me/export`): Allows users to download all their personal data stored in OpenRegister, fulfilling the GDPR right of data portability.
- **Account Deactivation** (`POST /api/user/me/deactivate`): Users can request account deactivation with a grace period, fulfilling the GDPR right to erasure. The deactivation can be checked (`GET .../deactivation-status`) or cancelled (`DELETE .../deactivate`) during the grace period.

## API Test Results (2026-03-25)

All profile endpoints return HTTP 500 due to an external dependency error in OpenConnector:

| Endpoint | Method | HTTP Status | Error |
|----------|--------|-------------|-------|
| `/api/user/me` | GET | 500 | OpenConnector EventListener instantiation failure |
| `/api/user/me/notifications` | GET | 500 | Same |
| `/api/user/me/notifications` | PUT | 500 | Same |
| `/api/user/me/activity` | GET | 500 | Same |
| `/api/user/me/tokens` | GET | 500 | Same |
| `/api/user/me/tokens` | POST | 500 | Same |
| `/api/user/me/deactivation-status` | GET | 500 | Same |

### Root Cause

The 500 errors are caused by:
```
OCP\AppFramework\QueryException: Could not resolve
OCA\OpenConnector\EventListener\ObjectUpdatedEventListener!
Class can not be instantiated.
```

This is an **OpenConnector app** dependency issue (its event listener class cannot be auto-loaded), not a bug in the OpenRegister `UserController` itself. The routes are correctly registered in `appinfo/routes.php` (lines 492-506) and the controller class exists with proper method signatures.

## Browser Verification (2026-03-25)

- **Contacts app** (`/apps/contacts`): Loaded successfully. The Nextcloud Contacts app is accessible and shows the standard header navigation bar including a "Search contacts" button in the top-right.
- **OpenRegister app** (`/apps/openregister`): Loaded successfully. Dashboard shows 10 registers, 105 schemas, 44,756 objects across registers (Publication, AMEF, Voorzieningen, Procest, LarpingApp, Pipelinq, and others). Navigation sidebar includes AI Chat, Registers, Schemas, Templates, Search/Views, Files, Agents, and Settings.
