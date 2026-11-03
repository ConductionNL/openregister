---
title: "Add Security Settings UI for Rate Limit Management"
labels: ["enhancement", "frontend", "security"]
assignees: []
milestone: ""
---

## Description

Implement a UI in the admin settings section to manage security rate limits. This includes the ability to:
- View currently blocked IPs and locked user accounts
- Unblock specific IP addresses
- Unlock specific user accounts
- View rate limit configuration

## Background

New API endpoints have been added to manage rate limits:
- `POST /api/settings/security/unblock-ip` - Unblock an IP address
- `POST /api/settings/security/unblock-user` - Unblock a user account
- `POST /api/settings/security/unblock` - Unblock both IP and user

## Acceptance Criteria

- [ ] Add a "Security" tab/section in the admin settings
- [ ] Display a form to unblock IP addresses with input field and submit button
- [ ] Display a form to unblock user accounts with input field and submit button
- [ ] Show success/error notifications after actions
- [ ] Add confirmation dialog before unblocking
- [ ] Document the security settings in the user guide

## Technical Details

### API Endpoints

```typescript
// Unblock IP
POST /api/settings/security/unblock-ip
Body: { "ip": "192.168.1.1" }

// Unblock User
POST /api/settings/security/unblock-user
Body: { "username": "user@example.com" }

// Unblock Both
POST /api/settings/security/unblock
Body: { "ip": "192.168.1.1", "username": "user@example.com" }
```

### Files to Create/Modify

- `src/views/settings/SecuritySettings.vue` - New component
- `src/store/modules/security.js` - State management (if using Vuex/Pinia)
- Add route and navigation item for security settings

### UI Mockup

```
Security Settings
─────────────────────────────────────

Rate Limit Management
━━━━━━━━━━━━━━━━━━━━━

Unblock IP Address
┌─────────────────────────────────┐
│ IP Address: [________________]  │
│                    [Unblock IP] │
└─────────────────────────────────┘

Unblock User Account
┌─────────────────────────────────┐
│ Username:   [________________]  │
│                  [Unblock User] │
└─────────────────────────────────┘
```

## Related

- SecuritySettingsController.php - Backend implementation
- SecurityService.php - Rate limit logic
- `website/docs/development/security-architecture.md` - Security documentation
