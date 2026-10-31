# Proposal: notification-preferences-one-row-per-slug

## Why

DqPersoonlijkeInstellingen (dossiq user settings, Meldingen) listed "Mijn dagelijkse werkoverzicht" twice. On the
instance two schema rows carry the slug `workDigest` for dossiq: 1515 (version 1.0.0, in no register, no objects,
left behind by an earlier import) and 1532 (1.0.1, in the dossiq register). `/api/notification-preferences` lists the
rules of every schema, and a preference is keyed by the slug, so the second row could only repeat the first.

## What changes

- The effective preference list reads one schema per owning app and slug: the highest version, then the highest id.
  Another app's schema with the same slug stays its own row.

## Scope

NotificationPreferenceService::getEffectiveForUser. The orphaned row itself is instance data, removed by whoever
cleans the instance.
