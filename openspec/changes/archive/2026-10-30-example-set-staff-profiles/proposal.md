# Proposal: example-set-staff-profiles

## Why

Dossiq's departments page (DqAfdelingen) lists a team's staff: each person's function (the profile headline), roles (the profile role), e-mail, and today's presence (their absence, or their user status). The example set creates the cast's accounts with a name and an e-mail only. As a result every row shows an empty function and every person shows as present.

## What changes

- A cast person's existing `function` is written as the profile headline.
- A new optional `roles` field (a list or a text) is written as the profile role.
- A new optional `absence` (`fromDay`, `untilDay` counted from the install day, `status`, `message`) is written as the dav app's out of office.
- A new optional `status` (`status`, `message`, `icon`) is written as the user status.
- All of these are written only on accounts the set created, at every install, so a changed cast reaches an instance that already has the account. An adopted or found account is left alone.
- A part whose app is missing is named in `dropped` and is never fatal.
- The Zuiddrecht cast gains three Vergunningen staff (Jamal Ouali, out; Sanne Bakker, away until next week; Mehmet Yıldız). Pieter's function becomes Woo-coördinator, as every board names him.
