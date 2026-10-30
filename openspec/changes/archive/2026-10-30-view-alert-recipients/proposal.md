---
kind: code
---

# Proposal: view-alert-recipients

## Summary

A saved view's count alert sends the view's name and count to its recipients.
Saving an alert now checks who it names. The owner and an administrator may add
any user or group the view reaches. A member with write access may add only
themselves. Recipients already on the stored alert are not judged again, so a
member can still change the threshold of an alert the owner set up.

## Why

Write members may change `alert`, and the save only checked that
`alert.recipients` was a non-empty list of strings. A write member could name
any user, who then received the count of a view they could not read.

## Scope

- `ViewerReachResolver::assertMayNameRecipients()` decides and throws `ViewAlertRecipientException`; `ViewsController`
  create, update and patch answer 403 with `field: alert.recipients` and the
  refused recipients.
- Out of scope: re-checking recipients at delivery time after the owner
  narrows the shares.
