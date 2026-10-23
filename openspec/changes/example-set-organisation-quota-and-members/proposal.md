## Why

dossiq's DqTenant board draws Gemeente Waterland with its quota (storage 100 GB) and lets Pieter read it. An example set could create an OpenRegister organisation from its cast, but only with a name, slug and description:
- no quota, so the quota meters read "no limit";
- no members, so nobody but an administrator could read it. Pieter's `/api/organisations` listed only the default organisation.

## What Changes

- A cast's `openregisterOrganisations` entry MAY declare:
  - `quota`: `storage` in bytes, `requests` and `bandwidth`, each a whole number;
  - `members`: cast people's keys.
- A new organisation is created with that quota and with the members' accounts.
- The catalogue refuses an unknown quota key, a value that is not a whole number, and a member who is not a cast person with an account.
- Remove still deletes the organisation, because its members are the set's own accounts.
- The Zuiddrecht cast gives Waterland a quota of 100 GB storage and 5,000 requests, with Marieke Visser and Pieter as members.

Measured usage, such as the board's 88 GB used and 1,240 cases, is not seeded. OpenRegister measures it, and nothing has been written to measure in an example set.
