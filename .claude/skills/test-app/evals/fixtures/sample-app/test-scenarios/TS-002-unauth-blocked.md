---
id: TS-002
title: Unauthenticated access is blocked
status: active
priority: HIGH
perspective: security
test-commands: [test-app, test-security]
personas: [anonymous]
---

# TS-002: Unauthenticated access is blocked

## Given
- The user is NOT logged in

## When
- The user makes a request to `/api/objects/1/1` without auth headers

## Then
- The response is HTTP 401
- The response body does not contain any object data
