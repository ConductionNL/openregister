---
title: "Add UI to View Currently Blocked IPs and Locked Users"
labels: ["enhancement", "frontend", "security"]
assignees: []
milestone: ""
---

## Description

Extend the Security Settings UI to display a list of currently blocked IP addresses and locked user accounts. This provides administrators with visibility into the current security state.

## Background

The rate limiting system stores blocked IPs and locked users in the APCu cache. We need to add:
1. An API endpoint to retrieve the current blocked/locked entries
2. A UI component to display this information

## Acceptance Criteria

- [ ] Add API endpoint to list blocked IPs (requires new SecurityService method)
- [ ] Add API endpoint to list locked users (requires new SecurityService method)
- [ ] Display blocked IPs in a table with unblock action
- [ ] Display locked users in a table with unlock action
- [ ] Show lockout expiration time for each entry
- [ ] Add refresh button to update the list
- [ ] Handle empty state when no blocks/locks exist

## Technical Details

### New API Endpoints Needed

```typescript
// Get blocked IPs
GET /api/settings/security/blocked-ips
Response: {
  "blocked_ips": [
    { "ip": "192.168.1.1", "lockout_until": 1234567890, "attempts": 5 }
  ]
}

// Get locked users
GET /api/settings/security/locked-users
Response: {
  "locked_users": [
    { "username": "user@example.com", "lockout_until": 1234567890, "attempts": 5 }
  ]
}
```

### Backend Changes Required

Add methods to SecurityService.php:
- `getBlockedIps(): array` - Retrieve all blocked IPs from cache
- `getLockedUsers(): array` - Retrieve all locked users from cache

Note: This requires iterating over cache keys or maintaining a separate index of blocked entries.

### UI Mockup

```
Currently Blocked
━━━━━━━━━━━━━━━━━

Blocked IP Addresses                               [Refresh]
┌──────────────────┬─────────────────────┬─────────────────┐
│ IP Address       │ Expires             │ Action          │
├──────────────────┼─────────────────────┼─────────────────┤
│ 192.168.1.100    │ in 45 minutes       │ [Unblock]       │
│ 10.0.0.50        │ in 12 minutes       │ [Unblock]       │
└──────────────────┴─────────────────────┴─────────────────┘

Locked User Accounts                               [Refresh]
┌──────────────────┬─────────────────────┬─────────────────┐
│ Username         │ Expires             │ Action          │
├──────────────────┼─────────────────────┼─────────────────┤
│ test@example.com │ in 30 minutes       │ [Unlock]        │
└──────────────────┴─────────────────────┴─────────────────┘
```

## Related

- Depends on: feature-security-settings-ui.md
- SecurityService.php - Backend implementation
