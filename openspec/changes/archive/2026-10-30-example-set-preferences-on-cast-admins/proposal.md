# Proposal: example-set-preferences-on-cast-admins

## Why

The Zuiddrecht set gives Pieter Jansen a saved view in his menu (`pref_cn_saved_view_menu`). On a development instance Pieter's account is also an administrator, so the check refused the whole set: a set never changed an administrator's preferences, even one the set's own cast declares. That blocked every reinstall of the set.

## What changes

- A preference MAY be written on an account the set's cast declares, created or adopted, also when that account is one of Nextcloud's administrators.
- A preference on an administrator outside the cast stays refused, now with its own reason. The set still never adds anyone to the administrators group (REQ-EXS-010).
