# Proposal: example-set-group-subadmins

## Why

Dossiq's departments page lists the groups its reader may list: an administrator's, or the groups the reader is a Nextcloud sub-admin of. The Zuiddrecht boards draw Pieter Jansen, the functional administrator, seeing the whole department tree, but he is a member of one team only and no administrator. An example set could make memberships, not sub-admins, so the tree read empty for him.

## What changes

- A cast group or a fragment group MAY name `subAdmins`: cast keys of people with an account. Install makes each one a Nextcloud sub-admin of that group (never a member, never an administrator), records it, and proves it; remove takes away only the sub-admin rights it made.
- A sub-admin key that is no cast person with an account refuses the set before anything is written.
- The Zuiddrecht cast makes Pieter sub-admin of every cast group.
